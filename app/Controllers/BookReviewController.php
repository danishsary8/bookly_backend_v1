<?php

namespace App\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Repositories\BookReviewRepository;
use RuntimeException;

class BookReviewController
{
    private BookReviewRepository $repo;

    public function __construct()
    {
        $this->repo = new BookReviewRepository();
    }

    public function index(int $bookId): void
    {
        try {
            $reviews = $this->repo->getForBook($bookId);
            ApiResponse::success(['data' => $reviews]);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Book not found' ? 404 : 400;
            ApiResponse::error($e->getMessage(), $status);
        }
    }

    public function store(int $bookId): void
    {
        try {
            $identity = Auth::requireRole(['customer']);
            $data = Request::jsonBody();

            $review = $this->repo->upsertForCustomer(
                $bookId,
                (int)$identity['id'],
                Validator::int($data, 'rating', 1, 5),
                Validator::string($data, 'comment', 1500)
            );

            ApiResponse::success([
                'message' => 'Review saved successfully',
                'data' => $review,
            ]);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Book not found' ? 404 : 400;
            ApiResponse::error($e->getMessage(), $status);
        }
    }
}
