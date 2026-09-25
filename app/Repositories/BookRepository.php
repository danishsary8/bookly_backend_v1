<?php

namespace App\Repositories;

use App\Config\DatabaseConnection;
use App\Models\BookModel;
use PDO;
use PDOException;
use RuntimeException;
use App\Repositories\InventoryMovementRepository;

class BookRepository
{
    private PDO $conn;
    private ?bool $hasSalesCountColumn = null;
    private ?bool $hasBookReviewsTable = null;
    private const DEFAULT_LIMIT = 24;
    private const MAX_LIMIT = 500;
    private InventoryMovementRepository $inventoryMovements;

    public function __construct()
    {
        $this->conn = DatabaseConnection::getInstance();
        $this->inventoryMovements = new InventoryMovementRepository();
    }

    private function hasSalesCountColumn(): bool
    {
        if ($this->hasSalesCountColumn !== null) {
            return $this->hasSalesCountColumn;
        }

        $sql = "
            SELECT EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_name = 'books'
                  AND column_name = 'sales_count'
            )
        ";

        $stmt = $this->conn->query($sql);
        $this->hasSalesCountColumn = (bool)$stmt->fetchColumn();

        return $this->hasSalesCountColumn;
    }

    private function hasBookReviewsTable(): bool
    {
        if ($this->hasBookReviewsTable !== null) {
            return $this->hasBookReviewsTable;
        }

        $stmt = $this->conn->query("
            SELECT EXISTS (
                SELECT 1
                FROM information_schema.tables
                WHERE table_name = 'book_reviews'
            )
        ");

        $this->hasBookReviewsTable = (bool)$stmt->fetchColumn();
        return $this->hasBookReviewsTable;
    }

    private function assertRelatedRecordsExist(int $authorId, int $categoryId): void
    {
        $authorStmt = $this->conn->prepare('SELECT id FROM authors WHERE id = :id');
        $authorStmt->execute(['id' => $authorId]);
        if (!$authorStmt->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException('Author not found');
        }

        $categoryStmt = $this->conn->prepare('SELECT id FROM categories WHERE id = :id');
        $categoryStmt->execute(['id' => $categoryId]);
        if (!$categoryStmt->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException('Category not found');
        }
    }

    private function getPurchaseCountExpression(): string
    {
        return $this->hasSalesCountColumn()
            ? "
                COALESCE(
                    (
                        SELECT SUM(ii.quantity)
                        FROM invoice_items ii
                        INNER JOIN invoices inv ON inv.id = ii.invoice_id
                        WHERE ii.book_id = books.id
                          AND inv.status <> 'cancelled'
                    ),
                    books.sales_count,
                    0
                )
            "
            : "
                COALESCE(
                    (
                        SELECT SUM(ii.quantity)
                        FROM invoice_items ii
                        INNER JOIN invoices inv ON inv.id = ii.invoice_id
                        WHERE ii.book_id = books.id
                          AND inv.status <> 'cancelled'
                    ),
                    0
                )
            ";
    }

    private function normalizeCatalogFilters(array $filters): array
    {
        $page = isset($filters['page']) ? max(1, (int)$filters['page']) : 1;
        $limit = isset($filters['limit']) ? max(1, min((int)$filters['limit'], self::MAX_LIMIT)) : self::DEFAULT_LIMIT;
        $sort = (string)($filters['sort'] ?? 'newest');
        $allowedSorts = ['newest', 'price_asc', 'price_desc', 'title_asc', 'title_desc', 'popular'];

        return [
            'search' => isset($filters['search']) ? trim((string)$filters['search']) : '',
            'category_id' => isset($filters['category_id']) && $filters['category_id'] !== '' ? (int)$filters['category_id'] : null,
            'author_id' => isset($filters['author_id']) && $filters['author_id'] !== '' ? (int)$filters['author_id'] : null,
            'min_price' => isset($filters['min_price']) && $filters['min_price'] !== '' ? (float)$filters['min_price'] : null,
            'max_price' => isset($filters['max_price']) && $filters['max_price'] !== '' ? (float)$filters['max_price'] : null,
            'sort' => in_array($sort, $allowedSorts, true) ? $sort : 'newest',
            'page' => $page,
            'limit' => $limit,
            'offset' => ($page - 1) * $limit,
        ];
    }

    private function buildCatalogConditions(array $filters): array
    {
        $conditions = [];
        $params = [];

        if ($filters['search'] !== '') {
            $conditions[] = '(books.title ILIKE :search OR authors.name ILIKE :search OR categories.name ILIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if ($filters['category_id'] !== null) {
            $conditions[] = 'books.category_id = :category_id';
            $params['category_id'] = $filters['category_id'];
        }

        if ($filters['author_id'] !== null) {
            $conditions[] = 'books.author_id = :author_id';
            $params['author_id'] = $filters['author_id'];
        }

        if ($filters['min_price'] !== null) {
            $conditions[] = 'books.price >= :min_price';
            $params['min_price'] = $filters['min_price'];
        }

        if ($filters['max_price'] !== null) {
            $conditions[] = 'books.price <= :max_price';
            $params['max_price'] = $filters['max_price'];
        }

        return [
            'where_sql' => $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '',
            'params' => $params,
        ];
    }

    private function bindCatalogParams(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
    }

    private function resolveSortSql(string $sort): string
    {
        return match ($sort) {
            'price_asc' => 'books.price ASC, books.id DESC',
            'price_desc' => 'books.price DESC, books.id DESC',
            'title_asc' => 'books.title ASC, books.id DESC',
            'title_desc' => 'books.title DESC, books.id DESC',
            'popular' => 'purchase_count DESC, books.id DESC',
            default => 'books.created_at DESC NULLS LAST, books.id DESC',
        };
    }

    private function getReviewStatsJoinSql(): string
    {
        if (!$this->hasBookReviewsTable()) {
            return '';
        }

        return "
            LEFT JOIN LATERAL (
                SELECT
                    ROUND(AVG(rating)::numeric, 2) AS average_rating,
                    COUNT(*) AS review_count
                FROM book_reviews
                WHERE book_id = books.id
            ) review_stats ON TRUE
        ";
    }

    private function getReviewStatsSelectSql(): string
    {
        if (!$this->hasBookReviewsTable()) {
            return '
                0::numeric AS average_rating,
                0::bigint AS review_count,
            ';
        }

        return '
            COALESCE(review_stats.average_rating, 0) AS average_rating,
            COALESCE(review_stats.review_count, 0) AS review_count,
        ';
    }

    public function saveBook(BookModel $book): array
    {
        try {
            $this->assertRelatedRecordsExist($book->getAuthorId(), $book->getCategoryId());

            $sql = "
                INSERT INTO books (
                    title,
                    author_id,
                    category_id,
                    price,
                    stock,
                    description,
                    published_date,
                    book_img
                ) VALUES (
                    :title,
                    :author_id,
                    :category_id,
                    :price,
                    :stock,
                    :description,
                    :published_date,
                    :book_img
                )
                RETURNING id
            ";

            $ps = $this->conn->prepare($sql);
            $ps->execute([
                'title' => $book->getTitle(),
                'author_id' => $book->getAuthorId(),
                'category_id' => $book->getCategoryId(),
                'price' => $book->getPrice(),
                'stock' => $book->getStock(),
                'description' => $book->getDescription(),
                'published_date' => $book->getPublishedDate() !== '' ? $book->getPublishedDate() : null,
                'book_img' => $book->getBookImage() !== '' ? $book->getBookImage() : null,
            ]);

            $bookId = (int)$ps->fetchColumn();
            $initialStock = $book->getStock();
            $this->inventoryMovements->record(
                $bookId,
                'created',
                $initialStock,
                0,
                $initialStock,
                'book',
                (string)$bookId,
                'Initial stock created with the book record',
            );
            return $this->getById($bookId) ?? throw new RuntimeException('Failed to fetch created book');
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to save book: ' . $e->getMessage());
        }
    }

    public function searchCatalog(array $filters = []): array
    {
        try {
            $normalizedFilters = $this->normalizeCatalogFilters($filters);
            $catalogFilters = $this->buildCatalogConditions($normalizedFilters);
            $whereSql = $catalogFilters['where_sql'];
            $params = $catalogFilters['params'];
            $purchaseCountSql = $this->getPurchaseCountExpression();
            $sortSql = $this->resolveSortSql($normalizedFilters['sort']);
            $reviewStatsJoinSql = $this->getReviewStatsJoinSql();
            $reviewStatsSelectSql = $this->getReviewStatsSelectSql();

            $countSql = "
                SELECT COUNT(*)
                FROM books
                INNER JOIN authors ON books.author_id = authors.id
                INNER JOIN categories ON books.category_id = categories.id
                {$whereSql}
            ";
            $countStmt = $this->conn->prepare($countSql);
            $this->bindCatalogParams($countStmt, $params);
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();

            $priceRangeSql = "
                SELECT
                    COALESCE(MIN(books.price), 0) AS min_price,
                    COALESCE(MAX(books.price), 0) AS max_price
                FROM books
                INNER JOIN authors ON books.author_id = authors.id
                INNER JOIN categories ON books.category_id = categories.id
                {$whereSql}
            ";
            $priceRangeStmt = $this->conn->prepare($priceRangeSql);
            $this->bindCatalogParams($priceRangeStmt, $params);
            $priceRangeStmt->execute();
            $priceRange = $priceRangeStmt->fetch(PDO::FETCH_ASSOC) ?: ['min_price' => 0, 'max_price' => 0];

            $itemsSql = "
                SELECT
                    books.id,
                    books.title,
                    books.description,
                    books.price,
                    books.stock,
                    books.created_at,
                    books.published_date,
                    books.book_img,
                    books.author_id,
                    books.category_id,
                    {$reviewStatsSelectSql}
                    {$purchaseCountSql} AS purchase_count,
                    authors.name AS author_name,
                    categories.name AS category_name
                FROM books
                INNER JOIN authors ON books.author_id = authors.id
                INNER JOIN categories ON books.category_id = categories.id
                {$reviewStatsJoinSql}
                {$whereSql}
                ORDER BY {$sortSql}
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->conn->prepare($itemsSql);
            $this->bindCatalogParams($stmt, $params);
            $stmt->bindValue(':limit', $normalizedFilters['limit'], PDO::PARAM_INT);
            $stmt->bindValue(':offset', $normalizedFilters['offset'], PDO::PARAM_INT);
            $stmt->execute();

            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totalPages = $total > 0 ? (int)ceil($total / $normalizedFilters['limit']) : 0;

            return [
                'items' => $items,
                'meta' => [
                    'page' => $normalizedFilters['page'],
                    'limit' => $normalizedFilters['limit'],
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'has_next_page' => $totalPages > $normalizedFilters['page'],
                    'has_previous_page' => $normalizedFilters['page'] > 1,
                    'sort' => $normalizedFilters['sort'],
                    'price_range' => [
                        'min' => (float)$priceRange['min_price'],
                        'max' => (float)$priceRange['max_price'],
                    ],
                ],
            ];
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to fetch books: ' . $e->getMessage());
        }
    }

    public function getAll(array $filters = []): array
    {
        return $this->searchCatalog($filters)['items'];
    }

    public function getById(int $id): ?array
    {
        try {
            $sql = "
                SELECT
                    books.*,
                    {$this->getReviewStatsSelectSql()}
                    authors.name AS author_name,
                    categories.name AS category_name
                FROM books
                INNER JOIN authors ON books.author_id = authors.id
                INNER JOIN categories ON books.category_id = categories.id
                {$this->getReviewStatsJoinSql()}
                WHERE books.id = :id
            ";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            $book = $stmt->fetch(PDO::FETCH_ASSOC);
            return $book ?: null;
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to fetch book: ' . $e->getMessage());
        }
    }

    public function updateBook(int $id, BookModel $book): array
    {
        try {
            $currentBook = $this->getById($id);
            if (!$currentBook) {
                throw new RuntimeException('Book not found');
            }

            $this->assertRelatedRecordsExist($book->getAuthorId(), $book->getCategoryId());

            $sql = "
                UPDATE books SET
                    title = :title,
                    author_id = :author_id,
                    category_id = :category_id,
                    price = :price,
                    stock = :stock,
                    description = :description,
                    published_date = :published_date,
                    book_img = :book_img
                WHERE id = :id
            ";

            $ps = $this->conn->prepare($sql);
            $ps->execute([
                'id' => $id,
                'title' => $book->getTitle(),
                'author_id' => $book->getAuthorId(),
                'category_id' => $book->getCategoryId(),
                'price' => $book->getPrice(),
                'stock' => $book->getStock(),
                'description' => $book->getDescription(),
                'published_date' => $book->getPublishedDate() !== '' ? $book->getPublishedDate() : null,
                'book_img' => $book->getBookImage() !== '' ? $book->getBookImage() : null,
            ]);

            $previousStock = (int)($currentBook['stock'] ?? 0);
            $nextStock = $book->getStock();
            if ($previousStock !== $nextStock) {
                $this->inventoryMovements->record(
                    $id,
                    'adjustment',
                    $nextStock - $previousStock,
                    $previousStock,
                    $nextStock,
                    'book',
                    (string)$id,
                    'Manual stock adjustment from catalog administration',
                );
            }

            return $this->getById($id) ?? throw new RuntimeException('Failed to fetch updated book');
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to update book: ' . $e->getMessage());
        }
    }

    public function deleteBook(int $id): void
    {
        try {
            if (!$this->getById($id)) {
                throw new RuntimeException('Book not found');
            }

            $sql = 'DELETE FROM books WHERE id = :id';
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to delete book: ' . $e->getMessage());
        }
    }

    public function getAllPrice(): array
    {
        try {
            $sql = 'SELECT DISTINCT price FROM books ORDER BY price DESC';
            $stmt = $this->conn->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to fetch books: ' . $e->getMessage());
        }
    }

    public function countBooks(): int
    {
        try {
            $sql = 'SELECT COUNT(*) AS total FROM books';
            $stmt = $this->conn->query($sql);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['total'];
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to count books: ' . $e->getMessage());
        }
    }

    public function getNewArrivals(int $limit = 10): array
    {
        try {
            $sql = "
                SELECT
                    books.id,
                    books.title,
                    books.description,
                    books.price,
                    books.stock,
                    books.created_at,
                    books.published_date,
                    books.book_img,
                    {$this->getReviewStatsSelectSql()}
                    authors.name AS author_name,
                    categories.name AS category_name
                FROM books
                INNER JOIN authors ON books.author_id = authors.id
                INNER JOIN categories ON books.category_id = categories.id
                {$this->getReviewStatsJoinSql()}
                ORDER BY books.created_at DESC NULLS LAST, books.id DESC
                LIMIT :limit
            ";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to fetch new arrivals: ' . $e->getMessage());
        }
    }

    public function getBestSellers(int $limit = 10): array
    {
        try {
            $purchaseCountSql = $this->getPurchaseCountExpression();

            $sql = "
                SELECT
                    books.id,
                    books.title,
                    books.description,
                    books.price,
                    books.stock,
                    books.created_at,
                    books.published_date,
                    books.book_img,
                    {$this->getReviewStatsSelectSql()}
                    {$purchaseCountSql} AS purchase_count,
                    authors.name AS author_name,
                    categories.name AS category_name
                FROM books
                INNER JOIN authors ON books.author_id = authors.id
                INNER JOIN categories ON books.category_id = categories.id
                {$this->getReviewStatsJoinSql()}
                ORDER BY purchase_count DESC, books.id DESC
                LIMIT :limit
            ";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to fetch best sellers: ' . $e->getMessage());
        }
    }
}
