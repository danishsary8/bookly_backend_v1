<?php

namespace App\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Repositories\ReturnRequestRepository;
use RuntimeException;

class ReturnController
{
    private ReturnRequestRepository $repo;

    public function __construct()
    {
        $this->repo = new ReturnRequestRepository();
    }

    public function customerStore(string $invoiceId): void
    {
        try {
            $identity = Auth::requireRole(['customer']);
            $data = Request::jsonBody();

            $returnRequest = $this->repo->createForCustomer(
                (int)$identity['id'],
                $invoiceId,
                Validator::int($data, 'invoice_item_id', 1),
                Validator::int($data, 'quantity', 1),
                Validator::string($data, 'reason', 2000)
            );

            ApiResponse::success([
                'message' => 'Return request submitted successfully',
                'data' => $returnRequest,
            ], 201);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Invoice not found' ? 404 : 400;
            ApiResponse::error($e->getMessage(), $status);
        }
    }

    public function adminIndex(): void
    {
        try {
            Auth::requireRole(['admin']);
            $returnRequests = $this->repo->adminGetAll();
            ApiResponse::success(['data' => $returnRequests]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function adminUpdate(string $returnId): void
    {
        try {
            Auth::requireRole(['admin']);
            $data = Request::jsonBody();

            $returnRequest = $this->repo->adminUpdate(
                (int)$returnId,
                Validator::oneOf($data, 'status', ['requested', 'approved', 'rejected', 'received', 'refunded']),
                Validator::nullableString($data, 'admin_note', 2000)
            );

            ApiResponse::success([
                'message' => 'Return request updated successfully',
                'data' => $returnRequest,
            ]);
        } catch (RuntimeException $e) {
            $status = $e->getMessage() === 'Return request not found' ? 404 : 400;
            ApiResponse::error($e->getMessage(), $status);
        }
    }
}
