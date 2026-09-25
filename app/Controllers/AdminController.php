<?php

namespace App\Controllers;

use App\Helpers\ApiResponse;
use App\Helpers\Auth;
use App\Helpers\AuthSession;
use App\Helpers\ClientIp;
use App\Helpers\RateLimiter;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\AdminModel;
use App\Repositories\AdminRepository;
use Exception;

class AdminController
{
    private AdminRepository $repository;

    public function __construct()
    {
        $this->repository = new AdminRepository();
    }

    public function store(): void
    {
        try {
            $data = Request::jsonBody();
            Validator::requireFields($data, ['first_name', 'last_name', 'email', 'password']);

            $data['first_name'] = Validator::string($data, 'first_name', 80);
            $data['last_name'] = Validator::string($data, 'last_name', 80);
            $data['email'] = Validator::email($data);
            $data['phone'] = Validator::nullableString($data, 'phone', 25) ?? '';
            $data['address'] = Validator::nullableString($data, 'address', 1000) ?? '';
            $password = Validator::password($data);

            $expectedSecret = (string)($_ENV['ADMIN_REGISTER_SECRET'] ?? '');
            $providedSecret = (string)($data['admin_register_secret'] ?? $data['secret'] ?? '');
            if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
                ApiResponse::error('Admin registration is not allowed without a valid secret', 403);
                return;
            }

            if ($this->repository->findByEmail($data['email'])) {
                ApiResponse::error('Email already exists', 409);
                return;
            }

            $data['password'] = password_hash($password, PASSWORD_DEFAULT);
            $admin = new AdminModel($data);

            if (!$this->repository->create($admin)) {
                throw new Exception('Admin create failed');
            }

            ApiResponse::success(['message' => 'Admin created successfully'], 201);
        } catch (Exception $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }

    public function login(): void
    {
        $data = Request::jsonBody();
        Validator::requireFields($data, ['email', 'password']);
        $email = Validator::email($data);
        $rateKey = strtolower($email) . '|' . ClientIp::current();
        RateLimiter::ensureAllowed('admin_login', $rateKey, 5, 900, 900);

        $adminData = $this->repository->findByEmail($email);
        if (!$adminData || !password_verify((string)$data['password'], $adminData['password'])) {
            RateLimiter::hit('admin_login', $rateKey, 5, 900, 900);
            ApiResponse::error('Invalid credentials', 401);
            return;
        }

        if (($adminData['role'] ?? '') !== 'admin') {
            ApiResponse::error('Access denied', 403);
            return;
        }

        RateLimiter::clear('admin_login', $rateKey);

        $identity = [
            'id' => $adminData['id'],
            'email' => $adminData['email'],
            'role' => 'admin',
            'first_name' => $adminData['first_name'] ?? '',
            'last_name' => $adminData['last_name'] ?? '',
            'phone' => $adminData['phone'] ?? null,
            'address' => $adminData['address'] ?? null,
            'name' => trim(($adminData['first_name'] ?? '') . ' ' . ($adminData['last_name'] ?? '')),
        ];

        $tokens = AuthSession::issueTokens($identity, true);

        ApiResponse::success([
            'data' => [
                ...$tokens,
                'user' => $identity,
            ],
        ]);
    }

    public function analytics(): void
    {
        try {
            Auth::requireRole(['admin']);
            $analytics = $this->repository->getDashboardAnalytics();
            ApiResponse::success(['data' => $analytics]);
        } catch (Exception $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function customers(): void
    {
        try {
            Auth::requireRole(['admin']);
            $customers = $this->repository->getCustomerDirectory();
            ApiResponse::success(['data' => $customers]);
        } catch (Exception $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function settings(): void
    {
        try {
            Auth::requireRole(['admin']);
            $settings = $this->repository->getStoreSettings();
            ApiResponse::success(['data' => $settings]);
        } catch (Exception $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function publicSettings(): void
    {
        try {
            $settings = $this->repository->getStoreSettings();
            ApiResponse::success([
                'data' => [
                    'store_name' => $settings['store_name'],
                    'support_email' => $settings['support_email'],
                    'support_phone' => $settings['support_phone'],
                    'hero_heading' => $settings['hero_heading'],
                    'hero_subheading' => $settings['hero_subheading'],
                    'free_shipping_threshold' => $settings['free_shipping_threshold'],
                    'shipping_fee' => $settings['shipping_fee'],
                    'tax_rate' => $settings['tax_rate'],
                ],
            ]);
        } catch (Exception $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    public function updateSettings(): void
    {
        try {
            Auth::requireRole(['admin']);
            $data = Request::jsonBody();

            $payload = [
                'store_name' => Validator::string($data, 'store_name', 160),
                'support_email' => Validator::email($data, 'support_email'),
                'support_phone' => Validator::nullableString($data, 'support_phone', 25),
                'hero_heading' => Validator::string($data, 'hero_heading', 255),
                'hero_subheading' => Validator::string($data, 'hero_subheading', 2000),
                'free_shipping_threshold' => Validator::float($data, 'free_shipping_threshold', 0),
                'shipping_fee' => Validator::float($data, 'shipping_fee', 0),
                'tax_rate' => Validator::float($data, 'tax_rate', 0),
                'low_stock_threshold' => Validator::int($data, 'low_stock_threshold', 0),
            ];

            $settings = $this->repository->updateStoreSettings($payload);
            ApiResponse::success([
                'message' => 'Store settings updated successfully',
                'data' => $settings,
            ]);
        } catch (Exception $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }
}
