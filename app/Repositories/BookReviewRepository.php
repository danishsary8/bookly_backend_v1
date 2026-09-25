<?php

namespace App\Repositories;

use App\Config\DatabaseConnection;
use PDO;
use RuntimeException;

class BookReviewRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
    }

    private function ensureBookExists(int $bookId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM books WHERE id = :id');
        $stmt->execute(['id' => $bookId]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Book not found');
        }
    }

    private function mapReview(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'book_id' => (int)$row['book_id'],
            'customer_id' => (int)$row['customer_id'],
            'customer_name' => (string)$row['customer_name'],
            'rating' => (int)$row['rating'],
            'comment' => (string)$row['comment'],
            'is_verified_purchase' => (bool)$row['is_verified_purchase'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }

    public function getForBook(int $bookId): array
    {
        $this->ensureBookExists($bookId);

        $stmt = $this->db->prepare("
            SELECT
                id,
                book_id,
                customer_id,
                customer_name,
                rating,
                comment,
                is_verified_purchase,
                created_at,
                updated_at
            FROM book_reviews
            WHERE book_id = :book_id
            ORDER BY is_verified_purchase DESC, updated_at DESC, id DESC
        ");
        $stmt->execute(['book_id' => $bookId]);

        return array_map(fn(array $row): array => $this->mapReview($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function customerHasPurchasedBook(int $customerId, int $bookId): bool
    {
        $stmt = $this->db->prepare("
            SELECT EXISTS (
                SELECT 1
                FROM invoice_items ii
                INNER JOIN invoices inv ON inv.id = ii.invoice_id
                WHERE inv.customer_id = :customer_id
                  AND ii.book_id = :book_id
                  AND inv.status IN ('paid', 'processing', 'shipped', 'delivered')
            )
        ");
        $stmt->execute([
            'customer_id' => $customerId,
            'book_id' => $bookId,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    private function getCustomerDisplayName(int $customerId): string
    {
        $stmt = $this->db->prepare("
            SELECT
                first_name,
                last_name,
                email
            FROM customers
            WHERE id = :id
        ");
        $stmt->execute(['id' => $customerId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            throw new RuntimeException('Customer not found');
        }

        $name = trim((string)($customer['first_name'] ?? '') . ' ' . (string)($customer['last_name'] ?? ''));
        return $name !== '' ? $name : (string)$customer['email'];
    }

    public function upsertForCustomer(int $bookId, int $customerId, int $rating, string $comment): array
    {
        $this->ensureBookExists($bookId);

        if (!$this->customerHasPurchasedBook($customerId, $bookId)) {
            throw new RuntimeException('Only customers who purchased this book can submit a review');
        }

        $customerName = $this->getCustomerDisplayName($customerId);

        $stmt = $this->db->prepare("
            INSERT INTO book_reviews (
                book_id,
                customer_id,
                customer_name,
                rating,
                comment,
                is_verified_purchase
            )
            VALUES (
                :book_id,
                :customer_id,
                :customer_name,
                :rating,
                :comment,
                TRUE
            )
            ON CONFLICT (book_id, customer_id)
            DO UPDATE SET
                customer_name = EXCLUDED.customer_name,
                rating = EXCLUDED.rating,
                comment = EXCLUDED.comment,
                is_verified_purchase = EXCLUDED.is_verified_purchase,
                updated_at = CURRENT_TIMESTAMP
            RETURNING
                id,
                book_id,
                customer_id,
                customer_name,
                rating,
                comment,
                is_verified_purchase,
                created_at,
                updated_at
        ");
        $stmt->execute([
            'book_id' => $bookId,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'rating' => $rating,
            'comment' => $comment,
        ]);

        $review = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$review) {
            throw new RuntimeException('Failed to save review');
        }

        return $this->mapReview($review);
    }
}
