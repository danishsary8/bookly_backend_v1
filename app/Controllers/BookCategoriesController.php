<?php
namespace App\Controllers;
use App\Helpers\ApiResponse;
use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Repositories\BookCategoriesRepository;
use RuntimeException;

class BookCategoriesController{
      private BookCategoriesRepository $repo;
      public function __construct() {
        $this->repo = new BookCategoriesRepository();
      }

      public function index(){
        $data = $this->repo->getAllCategory();
        ApiResponse::success([
          "data" => $data
        ]);
      }

      public function store(): void
      {
        try {
          Auth::requireRole(['admin']);
          $data = Request::jsonBody();
          $category = $this->repo->create(Validator::string($data, 'name', 80));
          ApiResponse::success([
            'message' => 'Category created successfully',
            'data' => $category,
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
          $category = $this->repo->update($id, Validator::string($data, 'name', 80));
          ApiResponse::success([
            'message' => 'Category updated successfully',
            'data' => $category,
          ]);
        } catch (RuntimeException $e) {
          $status = $e->getMessage() === 'Category not found' ? 404 : 400;
          ApiResponse::error($e->getMessage(), $status);
        }
      }

      public function delete(int $id): void
      {
        try {
          Auth::requireRole(['admin']);
          $this->repo->delete($id);
          ApiResponse::success(['message' => 'Category deleted successfully']);
        } catch (RuntimeException $e) {
          $status = $e->getMessage() === 'Category not found' ? 404 : 400;
          ApiResponse::error($e->getMessage(), $status);
        }
      }
}
