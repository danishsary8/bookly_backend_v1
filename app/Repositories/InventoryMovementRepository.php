<?php

namespace App\Repositories;

use App\Config\DatabaseConnection;
use PDO;
use RuntimeException;

class InventoryMovementRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
    }

    public function record(
        int $bookId,
        string $changeType,
        int $quantityChange,
        int $stockBefore,
        int $stockAfter,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $note = null,
    ): void {
        if ($quantityChange === 0 && $stockBefore === $stockAfter) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO inventory_movements (
                book_id,
                change_type,
                quantity_change,
                stock_before,
                stock_after,
                reference_type,
                reference_id,
                note
            ) VALUES (
                :book_id,
                :change_type,
                :quantity_change,
                :stock_before,
                :stock_after,
                :reference_type,
                :reference_id,
                :note
            )
        ");

        $stmt->execute([
            'book_id' => $bookId,
            'change_type' => $changeType,
            'quantity_change' => $quantityChange,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
        ]);
    }

    public function getRecent(int $limit = 40): array
    {
        $safeLimit = max(1, min($limit, 200));

        $stmt = $this->db->prepare("
            SELECT
                im.id,
                im.book_id,
                b.title AS book_title,
                a.name AS author_name,
                im.change_type,
                im.quantity_change,
                im.stock_before,
                im.stock_after,
                im.reference_type,
                im.reference_id,
                im.note,
                im.created_at
            FROM inventory_movements im
            INNER JOIN books b ON b.id = im.book_id
            INNER JOIN authors a ON a.id = b.author_id
            ORDER BY im.created_at DESC, im.id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
