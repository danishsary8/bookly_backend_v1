<?php

namespace App\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Repositories\InvoiceRepository;
use RuntimeException;

class InvoiceController
{
    private InvoiceRepository $repo;

    public function __construct()
    {
        $this->repo = new InvoiceRepository();
    }

    public function checkout(): void
    {
        try {
            $identity = Auth::requireRole(['customer']);
            $data = Request::jsonBody();

            $paymentMethod = Validator::oneOf($data, 'payment_method', ['card', 'cod'], 'card');
            $shippingAddress = $data['shipping_address'] ?? $data['shippingAddress'] ?? null;
            if (is_array($shippingAddress)) {
                $shippingAddress = json_encode($shippingAddress);
            }

            $invoice = $this->repo->checkout(
                $identity['id'],
                (string)$paymentMethod,
                $shippingAddress !== null ? trim((string)$shippingAddress) : null,
                Validator::nullableString($data, 'promo_code', 40)
            );

            ApiResponse::success(['data' => $invoice], 201);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function preview(): void
    {
        try {
            $identity = Auth::requireRole(['customer']);
            $data = Request::jsonBody();
            $summary = $this->repo->previewCheckout(
                $identity['id'],
                Validator::nullableString($data, 'promo_code', 40)
            );
            ApiResponse::success(['data' => $summary]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function index(): void
    {
        try {
            $identity = Auth::requireRole(['customer']);
            $invoices = $this->repo->getInvoicesForCustomer($identity['id']);
            ApiResponse::success(['data' => $invoices]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function show(string $invoiceId): void
    {
        try {
            $identity = Auth::requireRole(['customer']);
            $invoice = $this->repo->getInvoiceForCustomer($identity['id'], $invoiceId);
            ApiResponse::success(['data' => $invoice]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 404);
        }
    }

    public function adminIndex(): void
    {
        try {
            Auth::requireRole(['admin']);
            $invoices = $this->repo->adminGetInvoices();
            ApiResponse::success(['data' => $invoices]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function adminUpdate(string $invoiceId): void
    {
        try {
            Auth::requireRole(['admin']);
            $data = Request::jsonBody();
            $status = Validator::oneOf($data, 'status', ['pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled']);
            $invoice = $this->repo->adminUpdateInvoiceStatus($invoiceId, (string)$status, [
                'carrier' => Validator::nullableString($data, 'carrier', 120),
                'tracking_number' => Validator::nullableString($data, 'tracking_number', 120),
                'tracking_url' => Validator::nullableString($data, 'tracking_url', 2000),
                'estimated_delivery_at' => Validator::nullableString($data, 'estimated_delivery_at', 40),
            ]);

            ApiResponse::success(['data' => $invoice]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }
}
