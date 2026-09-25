<?php

namespace App\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Auth;
use App\Helpers\Request;
use App\Repositories\InventoryMovementRepository;
use RuntimeException;

class InventoryController
{
    private InventoryMovementRepository $repo;

    public function __construct()
    {
        $this->repo = new InventoryMovementRepository();
    }

    public function index(): void
    {
        try {
            Auth::requireRole(['admin']);
            $limit = (int)Request::query('limit', 40);
            $movements = $this->repo->getRecent($limit);
            ApiResponse::success(['data' => $movements]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }
}
