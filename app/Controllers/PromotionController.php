<?php

namespace App\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Repositories\PromotionRepository;
use RuntimeException;

class PromotionController
{
    private PromotionRepository $repo;

    public function __construct()
    {
        $this->repo = new PromotionRepository();
    }

    public function index(): void
    {
        try {
            Auth::requireRole(['admin']);
            ApiResponse::success(['data' => $this->repo->getAll()]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function store(): void
    {
        try {
            Auth::requireRole(['admin']);
            $payload = $this->validatedPayload(Request::jsonBody());
            $promotion = $this->repo->create($payload);
            ApiResponse::success([
                'message' => 'Promotion created successfully',
                'data' => $promotion,
            ], 201);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function update(int $id): void
    {
        try {
            Auth::requireRole(['admin']);
            $payload = $this->validatedPayload(Request::jsonBody());
            $promotion = $this->repo->update($id, $payload);
            ApiResponse::success([
                'message' => 'Promotion updated successfully',
                'data' => $promotion,
            ]);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Promotion not found' ? 404 : 400;
            ApiResponse::error($e->getMessage(), $status);
        }
    }

    public function delete(int $id): void
    {
        try {
            Auth::requireRole(['admin']);
            $this->repo->delete($id);
            ApiResponse::success(['message' => 'Promotion deleted successfully']);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Promotion not found' ? 404 : 400;
            ApiResponse::error($e->getMessage(), $status);
        }
    }

    private function validatedPayload(array $data): array
    {
        $discountType = Validator::oneOf($data, 'discount_type', ['percent', 'fixed']);
        $discountValue = Validator::float($data, 'discount_value', 0);

        if ($discountType === 'percent' && $discountValue > 100) {
            ApiResponse::error('discount_value must not exceed 100 for percent promotions', 422);
            exit;
        }

        $startsAt = Validator::nullableString($data, 'starts_at', 40);
        $endsAt = Validator::nullableString($data, 'ends_at', 40);

        return [
            'code' => Validator::string($data, 'code', 40),
            'description' => Validator::nullableString($data, 'description', 500),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'min_subtotal' => Validator::float($data, 'min_subtotal', 0),
            'starts_at' => $startsAt !== null ? str_replace('T', ' ', $startsAt) : null,
            'ends_at' => $endsAt !== null ? str_replace('T', ' ', $endsAt) : null,
            'is_active' => (bool)($data['is_active'] ?? true),
        ];
    }
}
