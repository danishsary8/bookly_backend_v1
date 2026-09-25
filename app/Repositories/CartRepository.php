<?php

namespace App\Repositories;

use App\Config\DatabaseConnection;
use PDO;
use RuntimeException;

class CartRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
    }

    private function getOrCreateCartId(int $customerId): int
    {
        $select = $this->db->prepare('SELECT id FROM carts WHERE customer_id = :customer_id LIMIT 1');
        $select->execute(['customer_id' => $customerId]);
        $existingId = $select->fetchColumn();
        if ($existingId !== false) {
            return (int)$existingId;
        }

        $insert = $this->db->prepare('INSERT INTO carts (customer_id) VALUES (:customer_id) RETURNING id');
        $insert->execute(['customer_id' => $customerId]);
        return (int)$insert->fetchColumn();
    }

    private function getBookForCart(int $bookId): array
    {
        $stmt = $this->db->prepare('SELECT id, stock FROM books WHERE id = :id');
        $stmt->execute(['id' => $bookId]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$book) {
            throw new RuntimeException('Book not found');
        }

        return $book;
    }

    public function getCartWithItems(int $customerId): array
    {
        $cartId = $this->getOrCreateCartId($customerId);

        $sql = "
            SELECT
                ci.book_id,
                ci.quantity,
                b.title,
                b.description,
                b.price,
                b.stock,
                b.created_at,
                b.published_date,
                b.book_img,
                a.name AS author_name,
                cat.name AS category_name
            FROM cart_items ci
            INNER JOIN books b ON ci.book_id = b.id
            INNER JOIN authors a ON b.author_id = a.id
            INNER JOIN categories cat ON b.category_id = cat.id
            WHERE ci.cart_id = :cart_id
            ORDER BY ci.created_at DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['cart_id' => $cartId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $subtotal = 0.0;
        foreach ($items as &$item) {
            $item['quantity'] = (int)$item['quantity'];
            $item['price'] = (float)$item['price'];
            $item['stock'] = (int)$item['stock'];
            $item['line_total'] = $item['price'] * $item['quantity'];
            $subtotal += $item['line_total'];
        }
        unset($item);

        return [
            'cart_id' => $cartId,
            'item_count' => count($items),
            'subtotal' => round($subtotal, 2),
            'items' => $items,
        ];
    }

    public function addItem(int $customerId, int $bookId, int $quantity): void
    {
        $quantity = max(1, $quantity);
        $cartId = $this->getOrCreateCartId($customerId);
        $book = $this->getBookForCart($bookId);

        $existingStmt = $this->db->prepare('SELECT quantity FROM cart_items WHERE cart_id = :cart_id AND book_id = :book_id');
        $existingStmt->execute([
            'cart_id' => $cartId,
            'book_id' => $bookId,
        ]);
        $existingQty = (int)($existingStmt->fetchColumn() ?: 0);
        $newQty = $existingQty + $quantity;

        if ($newQty > (int)$book['stock']) {
            throw new RuntimeException('Requested quantity exceeds available stock');
        }

        if ($existingQty > 0) {
            $stmt = $this->db->prepare('UPDATE cart_items SET quantity = :quantity WHERE cart_id = :cart_id AND book_id = :book_id');
            $stmt->execute([
                'quantity' => $newQty,
                'cart_id' => $cartId,
                'book_id' => $bookId,
            ]);
            return;
        }

        $stmt = $this->db->prepare('INSERT INTO cart_items (cart_id, book_id, quantity) VALUES (:cart_id, :book_id, :quantity)');
        $stmt->execute([
            'cart_id' => $cartId,
            'book_id' => $bookId,
            'quantity' => $quantity,
        ]);
    }

    public function setItemQuantity(int $customerId, int $bookId, int $quantity): void
    {
        $quantity = max(1, $quantity);
        $cartId = $this->getOrCreateCartId($customerId);
        $book = $this->getBookForCart($bookId);

        if ($quantity > (int)$book['stock']) {
            throw new RuntimeException('Requested quantity exceeds available stock');
        }

        $sql = "
            INSERT INTO cart_items (cart_id, book_id, quantity)
            VALUES (:cart_id, :book_id, :quantity)
            ON CONFLICT (cart_id, book_id) DO UPDATE
            SET quantity = EXCLUDED.quantity
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'cart_id' => $cartId,
            'book_id' => $bookId,
            'quantity' => $quantity,
        ]);
    }

    public function removeItem(int $customerId, int $bookId): void
    {
        $cartId = $this->getOrCreateCartId($customerId);
        $sql = 'DELETE FROM cart_items WHERE cart_id = :cart_id AND book_id = :book_id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['cart_id' => $cartId, 'book_id' => $bookId]);
    }
}
