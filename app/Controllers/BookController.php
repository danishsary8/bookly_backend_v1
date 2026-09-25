<?php

namespace App\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\BookModel;
use App\Repositories\BookRepository;
use RuntimeException;

class BookController
{
    private BookRepository $repo;

    public function __construct()
    {
        $this->repo = new BookRepository();
    }

    private function hydrateBookModel(array $data): BookModel
    {
        Validator::requireFields($data, [
            'title',
            'author_id',
            'category_id',
            'price',
            'stock',
        ]);

        return new BookModel(
            Validator::string($data, 'title', 255),
            Validator::int($data, 'author_id', 1),
            Validator::int($data, 'category_id', 1),
            Validator::float($data, 'price', 0),
            Validator::int($data, 'stock', 0),
            Validator::nullableString($data, 'description', 10000) ?? '',
            Validator::nullableString($data, 'published_date', 20) ?? '',
            Validator::imageUrl($data, 'book_img', 2000) ?? ''
        );
    }

    public function save(): void
    {
        try {
            Auth::requireRole(['admin']);
            $book = $this->hydrateBookModel(Request::jsonBody());
            $created = $this->repo->saveBook($book);

            ApiResponse::success([
                'message' => 'Book created successfully',
                'data' => $created,
            ], 201);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function index(): void
    {
        try {
            $result = $this->repo->searchCatalog([
                'search' => Request::query('search'),
                'category_id' => Request::query('category_id'),
                'author_id' => Request::query('author_id'),
                'min_price' => Request::query('min_price'),
                'max_price' => Request::query('max_price'),
                'sort' => Request::query('sort', 'newest'),
                'page' => Request::query('page', 1),
                'limit' => Request::query('limit', 100),
            ]);

            ApiResponse::success([
                'data' => $result['items'],
                'meta' => $result['meta'],
            ]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function show(int $id): void
    {
        try {
            $book = $this->repo->getById($id);

            if (!$book) {
                ApiResponse::error('Book not found', 404);
                return;
            }

            ApiResponse::success(['data' => $book]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function update(int $id): void
    {
        try {
            Auth::requireRole(['admin']);
            $book = $this->hydrateBookModel(Request::jsonBody());
            $updated = $this->repo->updateBook($id, $book);

            ApiResponse::success([
                'message' => 'Book updated successfully',
                'data' => $updated,
            ]);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Book not found' ? 404 : 400;
            ApiResponse::error($e->getMessage(), $status);
        }
    }

    public function delete(int $id): void
    {
        try {
            Auth::requireRole(['admin']);
            $this->repo->deleteBook($id);
            ApiResponse::success(['message' => 'Book deleted successfully']);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Book not found' ? 404 : 400;
            ApiResponse::error($e->getMessage(), $status);
        }
    }

    public function getBookPrice(): void
    {
        try {
            $data = $this->repo->getAllPrice();
            ApiResponse::success(['data' => $data]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function countBooks(): void
    {
        try {
            $count = $this->repo->countBooks();
            ApiResponse::success(['total_books' => $count]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function newArrivals(): void
    {
        try {
            $books = $this->repo->getNewArrivals(10);
            ApiResponse::success(['data' => $books]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function bestSellers(): void
    {
        try {
            $books = $this->repo->getBestSellers(10);
            ApiResponse::success(['data' => $books]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }
}
