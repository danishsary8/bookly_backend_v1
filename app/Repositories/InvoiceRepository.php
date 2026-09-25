<?php

namespace App\Repositories;

use App\Config\DatabaseConnection;
use App\Repositories\PromotionRepository;
use PDO;
use RuntimeException;

class InvoiceRepository
{
    private PDO $db;
    private array $allowedStatuses = ['pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled'];
    private ?bool $hasSalesCountColumn = null;
    private InventoryMovementRepository $inventoryMovements;
    private PromotionRepository $promotions;
    private ReturnRequestRepository $returns;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
        $this->inventoryMovements = new InventoryMovementRepository();
        $this->promotions = new PromotionRepository();
        $this->returns = new ReturnRequestRepository();
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

    private function generateInvoiceId(): string
    {
        return 'BK-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
    }

    private function getStorePolicy(): array
    {
        try {
            $stmt = $this->db->query("
                SELECT
                    free_shipping_threshold,
                    shipping_fee,
                    tax_rate
                FROM store_settings
                WHERE id = 1
            ");
            $policy = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$policy) {
                return [
                    'free_shipping_threshold' => 60.0,
                    'shipping_fee' => 2.0,
                    'tax_rate' => 0.0,
                ];
            }

            return [
                'free_shipping_threshold' => (float)$policy['free_shipping_threshold'],
                'shipping_fee' => (float)$policy['shipping_fee'],
                'tax_rate' => (float)$policy['tax_rate'],
            ];
        } catch (\Throwable) {
            return [
                'free_shipping_threshold' => 60.0,
                'shipping_fee' => 2.0,
                'tax_rate' => 0.0,
            ];
        }
    }

    private function buildPreparedCartItems(int $customerId, bool $forUpdate = false): array
    {
        $sql = "
            SELECT
                ci.cart_id,
                ci.book_id,
                ci.quantity,
                b.stock,
                b.price,
                b.title,
                a.name AS author_name
            FROM cart_items ci
            INNER JOIN carts ct ON ci.cart_id = ct.id
            INNER JOIN books b ON ci.book_id = b.id
            INNER JOIN authors a ON b.author_id = a.id
            WHERE ct.customer_id = :customer_id
        ";

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['customer_id' => $customerId]);
        $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$cartItems) {
            throw new RuntimeException('Cart is empty');
        }

        $subtotal = 0.0;
        $preparedItems = [];
        foreach ($cartItems as $row) {
            $qty = (int)$row['quantity'];
            $stock = (int)$row['stock'];
            if ($qty <= 0) {
                throw new RuntimeException('Invalid cart quantity');
            }
            if ($stock < $qty) {
                throw new RuntimeException('Insufficient stock for one of the items');
            }

            $unitPrice = (float)$row['price'];
            $lineTotal = $unitPrice * $qty;
            $subtotal += $lineTotal;

            $preparedItems[] = [
                'cart_id' => (int)$row['cart_id'],
                'book_id' => (int)$row['book_id'],
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'title' => (string)$row['title'],
                'author_name' => (string)$row['author_name'],
                'stock_before' => $stock,
            ];
        }

        return [
            'cart_id' => (int)$preparedItems[0]['cart_id'],
            'subtotal' => $subtotal,
            'items' => $preparedItems,
        ];
    }

    private function buildPricingSummary(float $subtotal, ?string $promoCode = null): array
    {
        $normalizedPromoCode = $promoCode !== null ? strtoupper(trim($promoCode)) : null;
        $promotion = null;
        $discountAmount = 0.0;

        if ($normalizedPromoCode !== null && $normalizedPromoCode !== '') {
            $promotion = $this->promotions->findApplicableByCode($normalizedPromoCode, $subtotal);
            if (!$promotion) {
                throw new RuntimeException('Promotion code is invalid or not eligible');
            }
            $discountAmount = $this->promotions->calculateDiscountAmount($promotion, $subtotal);
        }

        $discountedSubtotal = max(0, round($subtotal - $discountAmount, 2));
        $policy = $this->getStorePolicy();
        $shipping = ($discountedSubtotal >= $policy['free_shipping_threshold'] || $discountedSubtotal === 0.0)
            ? 0.0
            : $policy['shipping_fee'];
        $tax = round($discountedSubtotal * $policy['tax_rate'], 2);
        $total = round($discountedSubtotal + $shipping + $tax, 2);

        return [
            'promo_code' => $promotion ? (string)$promotion['code'] : null,
            'discount_amount' => $discountAmount,
            'discounted_subtotal' => $discountedSubtotal,
            'shipping' => $shipping,
            'tax' => $tax,
            'total' => $total,
        ];
    }

    public function previewCheckout(int $customerId, ?string $promoCode = null): array
    {
        $cart = $this->buildPreparedCartItems($customerId, false);
        $pricing = $this->buildPricingSummary($cart['subtotal'], $promoCode);

        return [
            'subtotal' => round((float)$cart['subtotal'], 2),
            'discountAmount' => (float)$pricing['discount_amount'],
            'promoCode' => $pricing['promo_code'],
            'discountedSubtotal' => (float)$pricing['discounted_subtotal'],
            'shipping' => (float)$pricing['shipping'],
            'tax' => (float)$pricing['tax'],
            'total' => (float)$pricing['total'],
            'itemCount' => count($cart['items']),
        ];
    }

    public function checkout(int $customerId, string $paymentMethod, ?string $shippingAddress, ?string $promoCode = null): array
    {
        $paymentMethod = strtolower(trim($paymentMethod));
        if (!in_array($paymentMethod, ['card', 'cod'], true)) {
            throw new RuntimeException('Invalid payment method');
        }

        $shippingAddress = $shippingAddress !== null ? trim($shippingAddress) : '';
        if ($shippingAddress === '') {
            throw new RuntimeException('Shipping address is required');
        }

        $this->db->beginTransaction();
        try {
            $cart = $this->buildPreparedCartItems($customerId, true);

            $customerStmt = $this->db->prepare('SELECT id, email, first_name, last_name FROM customers WHERE id = :id');
            $customerStmt->execute(['id' => $customerId]);
            $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
            if (!$customer) {
                throw new RuntimeException('Customer not found');
            }

            $cartId = (int)$cart['cart_id'];
            $invoiceStatus = $paymentMethod === 'card' ? 'paid' : 'pending';
            $subtotal = round((float)$cart['subtotal'], 2);
            $preparedItems = $cart['items'];
            $pricing = $this->buildPricingSummary($subtotal, $promoCode);

            $invoiceId = null;
            for ($i = 0; $i < 3; $i++) {
                $candidate = $this->generateInvoiceId();
                $check = $this->db->prepare('SELECT id FROM invoices WHERE id = :id');
                $check->execute(['id' => $candidate]);
                if (!$check->fetch(PDO::FETCH_ASSOC)) {
                    $invoiceId = $candidate;
                    break;
                }
            }
            if (!$invoiceId) {
                throw new RuntimeException('Failed to generate invoice id');
            }

            $insertInvoice = $this->db->prepare("
                INSERT INTO invoices
                    (id, customer_id, customer_email, customer_name, payment_method, status, shipping_address,
                     subtotal, discount_amount, promo_code, shipping, tax, total)
                VALUES
                    (:id, :customer_id, :customer_email, :customer_name, :payment_method, :status, :shipping_address,
                     :subtotal, :discount_amount, :promo_code, :shipping, :tax, :total)
            ");

            $customerName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));

            $insertInvoice->execute([
                'id' => $invoiceId,
                'customer_id' => $customerId,
                'customer_email' => (string)$customer['email'],
                'customer_name' => $customerName !== '' ? $customerName : (string)$customer['email'],
                'payment_method' => $paymentMethod,
                'status' => $invoiceStatus,
                'shipping_address' => $shippingAddress,
                'subtotal' => $subtotal,
                'discount_amount' => $pricing['discount_amount'],
                'promo_code' => $pricing['promo_code'],
                'shipping' => $pricing['shipping'],
                'tax' => $pricing['tax'],
                'total' => $pricing['total'],
            ]);

            $insertItem = $this->db->prepare("
                INSERT INTO invoice_items
                    (invoice_id, book_id, title, author_name, unit_price, quantity, line_total)
                VALUES
                    (:invoice_id, :book_id, :title, :author_name, :unit_price, :quantity, :line_total)
            ");

            foreach ($preparedItems as $item) {
                if ($this->hasSalesCountColumn()) {
                    $this->db->prepare("
                        UPDATE books
                        SET stock = stock - :qty,
                            sales_count = sales_count + :qty
                        WHERE id = :book_id
                    ")->execute([
                        'qty' => $item['quantity'],
                        'book_id' => $item['book_id'],
                    ]);
                } else {
                    $this->db->prepare("
                        UPDATE books
                        SET stock = stock - :qty
                        WHERE id = :book_id
                    ")->execute([
                        'qty' => $item['quantity'],
                        'book_id' => $item['book_id'],
                    ]);
                }

                $this->inventoryMovements->record(
                    (int)$item['book_id'],
                    'sale',
                    -1 * (int)$item['quantity'],
                    (int)$item['stock_before'],
                    (int)$item['stock_before'] - (int)$item['quantity'],
                    'invoice',
                    $invoiceId,
                    'Stock deducted during customer checkout',
                );

                $insertItem->execute([
                    'invoice_id' => $invoiceId,
                    'book_id' => $item['book_id'],
                    'title' => $item['title'],
                    'author_name' => $item['author_name'],
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'line_total' => $item['line_total'],
                ]);
            }

            $this->db->prepare('DELETE FROM cart_items WHERE cart_id = :cart_id')->execute(['cart_id' => $cartId]);
            $this->db->commit();

            return $this->getInvoiceForCustomerInternal($customerId, $invoiceId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Checkout failed: ' . $e->getMessage());
        }
    }

    private function mapInvoiceRow(array $row): array
    {
        return [
            'id' => (string)$row['id'],
            'customerId' => (int)$row['customer_id'],
            'customerEmail' => (string)$row['customer_email'],
            'customerName' => (string)$row['customer_name'],
            'paymentMethod' => (string)$row['payment_method'],
            'status' => (string)$row['status'],
            'shippingAddress' => $row['shipping_address'] ?? '',
            'subtotal' => (float)$row['subtotal'],
            'discountAmount' => (float)($row['discount_amount'] ?? 0),
            'promoCode' => $row['promo_code'] !== null ? (string)$row['promo_code'] : null,
            'shipping' => (float)$row['shipping'],
            'tax' => (float)$row['tax'],
            'total' => (float)$row['total'],
            'carrier' => $row['carrier'] !== null ? (string)$row['carrier'] : null,
            'trackingNumber' => $row['tracking_number'] !== null ? (string)$row['tracking_number'] : null,
            'trackingUrl' => $row['tracking_url'] !== null ? (string)$row['tracking_url'] : null,
            'estimatedDeliveryAt' => $row['estimated_delivery_at'] !== null ? (string)$row['estimated_delivery_at'] : null,
            'deliveredAt' => $row['delivered_at'] !== null ? (string)$row['delivered_at'] : null,
            'createdAt' => (string)$row['created_at'],
            'updatedAt' => (string)($row['updated_at'] ?? $row['created_at']),
            'items' => [],
            'returnRequests' => [],
        ];
    }

    private function getInvoiceItems(string $invoiceId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                id,
                book_id,
                title,
                author_name,
                unit_price AS price,
                quantity,
                line_total AS total
            FROM invoice_items
            WHERE invoice_id = :invoice_id
            ORDER BY id ASC
        ");
        $stmt->execute(['invoice_id' => $invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getInvoiceForCustomerInternal(int $customerId, string $invoiceId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM invoices WHERE id = :id AND customer_id = :customer_id');
        $stmt->execute(['id' => $invoiceId, 'customer_id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Invoice not found');
        }

        $invoice = $this->mapInvoiceRow($row);
        $invoice['items'] = $this->getInvoiceItems($invoiceId);
        $invoice['returnRequests'] = $this->returns->getForInvoice($invoiceId);
        return $invoice;
    }

    private function getInvoiceById(string $invoiceId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM invoices WHERE id = :id');
        $stmt->execute(['id' => $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Invoice not found');
        }

        $invoice = $this->mapInvoiceRow($row);
        $invoice['items'] = $this->getInvoiceItems($invoiceId);
        $invoice['returnRequests'] = $this->returns->getForInvoice($invoiceId);
        return $invoice;
    }

    public function getInvoicesForCustomer(int $customerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM invoices WHERE customer_id = :customer_id ORDER BY created_at DESC');
        $stmt->execute(['customer_id' => $customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $invoices = [];
        foreach ($rows as $row) {
            $invoiceId = (string)$row['id'];
            $invoice = $this->mapInvoiceRow($row);
            $invoice['items'] = $this->getInvoiceItems($invoiceId);
            $invoice['returnRequests'] = $this->returns->getForInvoice($invoiceId);
            $invoices[] = $invoice;
        }
        return $invoices;
    }

    public function getInvoiceForCustomer(int $customerId, string $invoiceId): array
    {
        return $this->getInvoiceForCustomerInternal($customerId, $invoiceId);
    }

    public function adminGetInvoices(): array
    {
        $stmt = $this->db->query('SELECT * FROM invoices ORDER BY created_at DESC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $invoices = [];
        foreach ($rows as $row) {
            $invoiceId = (string)$row['id'];
            $invoice = $this->mapInvoiceRow($row);
            $invoice['items'] = $this->getInvoiceItems($invoiceId);
            $invoice['returnRequests'] = $this->returns->getForInvoice($invoiceId);
            $invoices[] = $invoice;
        }
        return $invoices;
    }

    public function adminUpdateInvoiceStatus(string $invoiceId, string $status, array $payload = []): array
    {
        $status = strtolower(trim($status));
        if (!in_array($status, $this->allowedStatuses, true)) {
            throw new RuntimeException('Invalid status');
        }

        $this->db->beginTransaction();
        try {
            $current = $this->getInvoiceById($invoiceId);
            $currentStatus = $current['status'];

            if ($currentStatus === 'cancelled' && $status !== 'cancelled') {
                throw new RuntimeException('Cancelled invoices cannot be reopened');
            }

            if ($currentStatus === 'delivered' && $status !== 'delivered') {
                throw new RuntimeException('Delivered invoices cannot be moved backwards');
            }

            if ($status === 'cancelled' && $currentStatus !== 'cancelled') {
                if ($currentStatus === 'delivered') {
                    throw new RuntimeException('Delivered invoices cannot be cancelled');
                }

                foreach ($current['items'] as $item) {
                    $stockStmt = $this->db->prepare('SELECT stock FROM books WHERE id = :book_id FOR UPDATE');
                    $stockStmt->execute(['book_id' => (int)$item['book_id']]);
                    $stockBefore = (int)$stockStmt->fetchColumn();

                    $sql = $this->hasSalesCountColumn()
                        ? 'UPDATE books SET stock = stock + :qty, sales_count = GREATEST(sales_count - :qty, 0) WHERE id = :book_id'
                        : 'UPDATE books SET stock = stock + :qty WHERE id = :book_id';
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([
                        'qty' => (int)$item['quantity'],
                        'book_id' => (int)$item['book_id'],
                    ]);

                    $stockAfter = $stockBefore + (int)$item['quantity'];
                    $this->inventoryMovements->record(
                        (int)$item['book_id'],
                        'restock',
                        (int)$item['quantity'],
                        $stockBefore,
                        $stockAfter,
                        'invoice',
                        $invoiceId,
                        'Stock restored because invoice was cancelled',
                    );
                }
            }

            $carrier = isset($payload['carrier']) ? trim((string)$payload['carrier']) : null;
            $trackingNumber = isset($payload['tracking_number']) ? trim((string)$payload['tracking_number']) : null;
            $trackingUrl = isset($payload['tracking_url']) ? trim((string)$payload['tracking_url']) : null;
            $estimatedDeliveryAt = isset($payload['estimated_delivery_at']) ? trim((string)$payload['estimated_delivery_at']) : null;

            $stmt = $this->db->prepare("
                UPDATE invoices
                SET
                    status = :status,
                    carrier = :carrier,
                    tracking_number = :tracking_number,
                    tracking_url = :tracking_url,
                    estimated_delivery_at = :estimated_delivery_at,
                    delivered_at = CASE
                        WHEN :status = 'delivered' AND delivered_at IS NULL THEN NOW()
                        WHEN :status <> 'delivered' THEN NULL
                        ELSE delivered_at
                    END,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                'status' => $status,
                'carrier' => $carrier !== '' ? $carrier : null,
                'tracking_number' => $trackingNumber !== '' ? $trackingNumber : null,
                'tracking_url' => $trackingUrl !== '' ? $trackingUrl : null,
                'estimated_delivery_at' => $estimatedDeliveryAt !== '' ? str_replace('T', ' ', $estimatedDeliveryAt) : null,
                'id' => $invoiceId,
            ]);

            $this->db->commit();
            return $this->getInvoiceById($invoiceId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Failed to update invoice: ' . $e->getMessage());
        }
    }
}
