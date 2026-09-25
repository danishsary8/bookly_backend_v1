<?php

namespace App\Repositories;

use App\Config\DatabaseConnection;
use PDO;
use PDOException;
use RuntimeException;

class AuthorsRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
    }

    public function getAllAuthors(): array
    {
        $sql = "
            SELECT
                a.id,
                a.name,
                COUNT(b.id) AS book_count
            FROM authors a
            LEFT JOIN books b ON b.author_id = a.id
            GROUP BY a.id, a.name
            ORDER BY a.name ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(string $name): array
    {
        try {
            $stmt = $this->db->prepare('INSERT INTO authors (name) VALUES (:name) RETURNING id, name');
            $stmt->execute(['name' => $name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'book_count' => 0,
            ];
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to create author: ' . $e->getMessage());
        }
    }

    public function update(int $id, string $name): array
    {
        try {
            $stmt = $this->db->prepare('UPDATE authors SET name = :name WHERE id = :id');
            $stmt->execute([
                'id' => $id,
                'name' => $name,
            ]);

            if ($stmt->rowCount() === 0) {
                $existsStmt = $this->db->prepare('SELECT id FROM authors WHERE id = :id');
                $existsStmt->execute(['id' => $id]);
                if (!$existsStmt->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException('Author not found');
                }
            }

            $author = $this->db->prepare("
                SELECT
                    a.id,
                    a.name,
                    COUNT(b.id) AS book_count
                FROM authors a
                LEFT JOIN books b ON b.author_id = a.id
                WHERE a.id = :id
                GROUP BY a.id, a.name
            ");
            $author->execute(['id' => $id]);
            $row = $author->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new RuntimeException('Author not found');
            }

            return [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'book_count' => (int)$row['book_count'],
            ];
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to update author: ' . $e->getMessage());
        }
    }

    public function delete(int $id): void
    {
        try {
            $usageStmt = $this->db->prepare('SELECT COUNT(*) FROM books WHERE author_id = :id');
            $usageStmt->execute(['id' => $id]);
            $usageCount = (int)$usageStmt->fetchColumn();
            if ($usageCount > 0) {
                throw new RuntimeException('Author cannot be deleted while books still reference it');
            }

            $stmt = $this->db->prepare('DELETE FROM authors WHERE id = :id');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Author not found');
            }
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to delete author: ' . $e->getMessage());
        }
    }
}
