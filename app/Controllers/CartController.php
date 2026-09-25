<?php

namespace App\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Repositories\CartRepository;
use RuntimeException;

class CartController
{
    private CartRepository $repo;

    public function __construct()
    {
        $this->repo = new CartRepository();
    }

    public function getCart(): void
    {
        try {
            $identity = Auth::requireRole(['customer']);
            $cart = $this->repo->getCartWithItems($identity['id']);

            ApiResponse::success(['data' => $cart]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function addItem(): void
    {
        try {
            $identity = Auth::requireRole(['customer']);
            $data = Request::jsonBody();
            $bookId = Validator::int($data, 'book_id', 1);
            $quantity = Validator::int($data, 'quantity', 1);

            $this->repo->addItem($identity['id'], $bookId, $quantity);
            $cart = $this->repo->getCartWithItems($identity['id']);

            ApiResponse::success(['data' => $cart], 201);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function setItemQuantity(int $book_id): void
    {
        try {
            $identity = Auth::requireRole(['customer']);
            $data = Request::jsonBody();
            $quantity = Validator::int($data, 'quantity', 1);

            $this->repo->setItemQuantity($identity['id'], $book_id, $quantity);
            $cart = $this->repo->getCartWithItems($identity['id']);

            ApiResponse::success(['data' => $cart]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function removeItem(int $book_id): void
    {
        try {
            $identity = Auth::requireRole(['customer']);
            if ($book_id <= 0) {
                ApiResponse::error('Invalid book_id', 400);
                return;
            }

            $this->repo->removeItem($identity['id'], $book_id);
            $cart = $this->repo->getCartWithItems($identity['id']);

            ApiResponse::success(['data' => $cart]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }
}
