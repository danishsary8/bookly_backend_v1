<?php
namespace App\Repositories;
use PDO;
use PDOException;
use App\Config\DatabaseConnection;
use RuntimeException;
class BookCategoriesRepository{
        private PDO $db;

        public function __construct() {
             $this->db = DatabaseConnection::getInstance();
        }

        // get All Book Category
        public function getAllCategory(){
           $sql = "
                SELECT
                    c.id,
                    c.name,
                    COUNT(b.id) AS book_count
                FROM categories c
                LEFT JOIN books b ON b.category_id = c.id
                GROUP BY c.id, c.name
                ORDER BY c.name ASC
           ";
           $stmt = $this->db->prepare($sql);
           $stmt->execute();
           return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        public function create(string $name): array
        {
            try {
                $stmt = $this->db->prepare('INSERT INTO categories (name) VALUES (:name) RETURNING id, name');
                $stmt->execute(['name' => $name]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                return [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                    'book_count' => 0,
                ];
            } catch (PDOException $e) {
                throw new RuntimeException('Failed to create category: ' . $e->getMessage());
            }
        }

        public function update(int $id, string $name): array
        {
            try {
                $stmt = $this->db->prepare('UPDATE categories SET name = :name WHERE id = :id');
                $stmt->execute([
                    'id' => $id,
                    'name' => $name,
                ]);

                if ($stmt->rowCount() === 0) {
                    $existsStmt = $this->db->prepare('SELECT id FROM categories WHERE id = :id');
                    $existsStmt->execute(['id' => $id]);
                    if (!$existsStmt->fetch(PDO::FETCH_ASSOC)) {
                        throw new RuntimeException('Category not found');
                    }
                }

                $category = $this->db->prepare("
                    SELECT
                        c.id,
                        c.name,
                        COUNT(b.id) AS book_count
                    FROM categories c
                    LEFT JOIN books b ON b.category_id = c.id
                    WHERE c.id = :id
                    GROUP BY c.id, c.name
                ");
                $category->execute(['id' => $id]);
                $row = $category->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    throw new RuntimeException('Category not found');
                }

                return [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                    'book_count' => (int)$row['book_count'],
                ];
            } catch (PDOException $e) {
                throw new RuntimeException('Failed to update category: ' . $e->getMessage());
            }
        }

        public function delete(int $id): void
        {
            try {
                $usageStmt = $this->db->prepare('SELECT COUNT(*) FROM books WHERE category_id = :id');
                $usageStmt->execute(['id' => $id]);
                $usageCount = (int)$usageStmt->fetchColumn();
                if ($usageCount > 0) {
                    throw new RuntimeException('Category cannot be deleted while books still reference it');
                }

                $stmt = $this->db->prepare('DELETE FROM categories WHERE id = :id');
                $stmt->execute(['id' => $id]);
                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException('Category not found');
                }
            } catch (PDOException $e) {
                throw new RuntimeException('Failed to delete category: ' . $e->getMessage());
            }
        }
}
