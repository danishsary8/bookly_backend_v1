<?php

namespace App\Repositories;

use App\Config\DatabaseConnection;
use PDO;
use RuntimeException;

class ReturnRequestRepository
{
    private PDO $db;
    private InventoryMovementRepository $inventoryMovements;
    private ?bool $hasSalesCountColumn = null;
    private array $allowedStatuses = ['requested', 'approved', 'rejected', 'received', 'refunded'];

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
        $this->inventoryMovements = new InventoryMovementRepository();
    }

    private function hasSalesCountColumn(): bool
    {
        if ($this->hasSalesCountColumn !== null) {
            return $this->hasSalesCountColumn;
        }

        $stmt = $this->db->query("
            SELECT EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_name = 'books'
                  AND column_name = 'sales_count'
            )
        ");

        $this->hasSalesCountColumn = (bool)$stmt->fetchColumn();
        return $this->hasSalesCountColumn;
    }

    private function mapReturnRequest(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'invoiceId' => (string)$row['invoice_id'],
            'invoiceItemId' => (int)$row['invoice_item_id'],
            'customerId' => (int)$row['customer_id'],
            'customerName' => (string)$row['customer_name'],
            'customerEmail' => isset($row['customer_email']) ? (string)$row['customer_email'] : '',
            'bookId' => (int)$row['book_id'],
            'bookTitle' => (string)$row['book_title'],
            'authorName' => isset($row['author_name']) ? (string)$row['author_name'] : '',
            'quantity' => (int)$row['quantity'],
            'reason' => (string)$row['reason'],
            'status' => (string)$row['status'],
            'adminNote' => $row['admin_note'] !== null ? (string)$row['admin_note'] : null,
            'refundAmount' => (float)$row['refund_amount'],
            'createdAt' => (string)$row['created_at'],
            'updatedAt' => (string)$row['updated_at'],
        ];
    }

    public function getForInvoice(string $invoiceId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                rr.*,
                ii.title AS book_title,
                ii.author_name
            FROM return_requests rr
            INNER JOIN invoice_items ii ON ii.id = rr.invoice_item_id
            WHERE rr.invoice_id = :invoice_id
            ORDER BY rr.created_at DESC, rr.id DESC
        ");
        $stmt->execute(['invoice_id' => $invoiceId]);

        return array_map(fn(array $row): array => $this->mapReturnRequest($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function adminGetAll(): array
    {
        $stmt = $this->db->query("
            SELECT
                rr.*,
                ii.title AS book_title,
                ii.author_name,
                inv.customer_email,
                inv.customer_name
            FROM return_requests rr
            INNER JOIN invoice_items ii ON ii.id = rr.invoice_item_id
            INNER JOIN invoices inv ON inv.id = rr.invoice_id
            ORDER BY rr.created_at DESC, rr.id DESC
        ");

        return array_map(fn(array $row): array => $this->mapReturnRequest($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function findByIdForUpdate(int $returnId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                rr.*,
                ii.title AS book_title,
                ii.author_name,
                inv.customer_email,
                inv.customer_name
            FROM return_requests rr
            INNER JOIN invoice_items ii ON ii.id = rr.invoice_item_id
            INNER JOIN invoices inv ON inv.id = rr.invoice_id
            WHERE rr.id = :id
            FOR UPDATE
        ");
        $stmt->execute(['id' => $returnId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Return request not found');
        }

        return $row;
    }

    public function createForCustomer(
        int $customerId,
        string $invoiceId,
        int $invoiceItemId,
        int $quantity,
        string $reason
    ): array {
        if ($quantity <= 0) {
            throw new RuntimeException('Return quantity must be greater than zero');
        }

        $this->db->beginTransaction();

        try {
            $invoiceStmt = $this->db->prepare("
                SELECT id, customer_id, status
                FROM invoices
                WHERE id = :invoice_id
                FOR UPDATE
            ");
            $invoiceStmt->execute(['invoice_id' => $invoiceId]);
            $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice || (int)$invoice['customer_id'] !== $customerId) {
                throw new RuntimeException('Invoice not found');
            }

            if ((string)$invoice['status'] !== 'delivered') {
                throw new RuntimeException('Returns are only allowed for delivered orders');
            }

            $itemStmt = $this->db->prepare("
                SELECT
                    id,
                    invoice_id,
                    book_id,
                    title,
                    author_name,
                    quantity,
                    unit_price
                FROM invoice_items
                WHERE id = :invoice_item_id
                  AND invoice_id = :invoice_id
                FOR UPDATE
            ");
            $itemStmt->execute([
                'invoice_item_id' => $invoiceItemId,
                'invoice_id' => $invoiceId,
            ]);
            $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                throw new RuntimeException('Invoice item not found');
            }

            $reservedStmt = $this->db->prepare("
                SELECT COALESCE(SUM(quantity), 0)
                FROM return_requests
                WHERE invoice_item_id = :invoice_item_id
                  AND status <> 'rejected'
            ");
            $reservedStmt->execute(['invoice_item_id' => $invoiceItemId]);
            $reservedQuantity = (int)$reservedStmt->fetchColumn();

            $purchasedQuantity = (int)$item['quantity'];
            $availableQuantity = max(0, $purchasedQuantity - $reservedQuantity);

            if ($quantity > $availableQuantity) {
                throw new RuntimeException('Requested return quantity exceeds the remaining returnable quantity');
            }

            $refundAmount = round(((float)$item['unit_price']) * $quantity, 2);

            $insertStmt = $this->db->prepare("
                INSERT INTO return_requests (
                    invoice_id,
                    invoice_item_id,
                    customer_id,
                    customer_name,
                    book_id,
                    quantity,
                    reason,
                    status,
                    admin_note,
                    refund_amount
                )
                VALUES (
                    :invoice_id,
                    :invoice_item_id,
                    :customer_id,
                    (
                        SELECT customer_name
                        FROM invoices
                        WHERE id = :invoice_id_lookup
                    ),
                    :book_id,
                    :quantity,
                    :reason,
                    'requested',
                    NULL,
                    :refund_amount
                )
                RETURNING id
            ");
            $insertStmt->execute([
                'invoice_id' => $invoiceId,
                'invoice_item_id' => $invoiceItemId,
                'invoice_id_lookup' => $invoiceId,
                'customer_id' => $customerId,
                'book_id' => (int)$item['book_id'],
                'quantity' => $quantity,
                'reason' => $reason,
                'refund_amount' => $refundAmount,
            ]);

            $returnId = (int)$insertStmt->fetchColumn();
            $this->db->commit();

            return $this->getById($returnId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException('Failed to create return request: ' . $e->getMessage());
        }
    }

    public function getById(int $returnId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                rr.*,
                ii.title AS book_title,
                ii.author_name,
                inv.customer_email,
                inv.customer_name
            FROM return_requests rr
            INNER JOIN invoice_items ii ON ii.id = rr.invoice_item_id
            INNER JOIN invoices inv ON inv.id = rr.invoice_id
            WHERE rr.id = :id
        ");
        $stmt->execute(['id' => $returnId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Return request not found');
        }

        return $this->mapReturnRequest($row);
    }

    private function assertStatusTransition(string $currentStatus, string $nextStatus): void
    {
        if (!in_array($nextStatus, $this->allowedStatuses, true)) {
            throw new RuntimeException('Invalid return status');
        }

        if ($currentStatus === $nextStatus) {
            return;
        }

        $allowedTransitions = [
            'requested' => ['approved', 'rejected'],
            'approved' => ['received', 'rejected', 'refunded'],
            'received' => ['refunded'],
            'rejected' => [],
            'refunded' => [],
        ];

        if (!in_array($nextStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            throw new RuntimeException('Invalid return status transition');
        }
    }

    public function adminUpdate(int $returnId, string $status, ?string $adminNote = null): array
    {
        $status = strtolower(trim($status));
        $normalizedNote = $adminNote !== null ? trim($adminNote) : null;

        $this->db->beginTransaction();

        try {
            $current = $this->findByIdForUpdate($returnId);
            $currentStatus = (string)$current['status'];
            $this->assertStatusTransition($currentStatus, $status);

            if ($status === 'refunded' && $currentStatus !== 'refunded') {
                $stockStmt = $this->db->prepare('SELECT stock FROM books WHERE id = :book_id FOR UPDATE');
                $stockStmt->execute(['book_id' => (int)$current['book_id']]);
                $stockBefore = (int)$stockStmt->fetchColumn();

                $updateBookSql = $this->hasSalesCountColumn()
                    ? 'UPDATE books SET stock = stock + :qty, sales_count = GREATEST(sales_count - :qty, 0) WHERE id = :book_id'
                    : 'UPDATE books SET stock = stock + :qty WHERE id = :book_id';

                $bookUpdateStmt = $this->db->prepare($updateBookSql);
                $bookUpdateStmt->execute([
                    'qty' => (int)$current['quantity'],
                    'book_id' => (int)$current['book_id'],
                ]);

                $stockAfter = $stockBefore + (int)$current['quantity'];
                $this->inventoryMovements->record(
                    (int)$current['book_id'],
                    'restock',
                    (int)$current['quantity'],
                    $stockBefore,
                    $stockAfter,
                    'return',
                    (string)$returnId,
                    'Stock restored after refund completion',
                );
            }

            $stmt = $this->db->prepare("
                UPDATE return_requests
                SET
                    status = :status,
                    admin_note = :admin_note,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute([
                'status' => $status,
                'admin_note' => $normalizedNote !== '' ? $normalizedNote : null,
                'id' => $returnId,
            ]);

            $this->db->commit();
            return $this->getById($returnId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException('Failed to update return request: ' . $e->getMessage());
        }
    }
}
