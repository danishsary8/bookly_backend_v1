<?php

namespace App\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Repositories\AuthorsRepository;
use RuntimeException;

class AuthorsController
{
    private AuthorsRepository $repo;

    public function __construct()
    {
        $this->repo = new AuthorsRepository();
    }

    public function index(): void
    {
        $data = $this->repo->getAllAuthors();

        ApiResponse::success(['data' => $data]);
    }

    public function store(): void
    {
        try {
            Auth::requireRole(['admin']);
            $data = Request::jsonBody();
            $author = $this->repo->create(Validator::string($data, 'name', 120));
            ApiResponse::success([
                'message' => 'Author created successfully',
                'data' => $author,
            ], 201);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function update(int $id): void
    {
        try {
            Auth::requireRole(['admin']);
            $data = Request::jsonBody();
            $author = $this->repo->update($id, Validator::string($data, 'name', 120));
            ApiResponse::success([
                'message' => 'Author updated successfully',
                'data' => $author,
            ]);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Author not found' ? 404 : 400;
            ApiResponse::error($e->getMessage(), $status);
        }
    }

    public function delete(int $id): void
    {
        try {
            Auth::requireRole(['admin']);
            $this->repo->delete($id);
            ApiResponse::success(['message' => 'Author deleted successfully']);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Author not found' ? 404 : 400;
            ApiResponse::error($e->getMessage(), $status);
        }
    }
}
