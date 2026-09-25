<?php
namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\AuthSession;
use App\Helpers\ApiResponse;
use App\Helpers\ClientIp;
use App\Helpers\Mailer;
use App\Helpers\RateLimiter;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\CustomerModel;
use App\Repositories\CustomerRepository;
use App\Repositories\RefreshTokenRepository;
use RuntimeException;
class CustomerController{
    private CustomerRepository $repository;
    private RefreshTokenRepository $refreshTokenRepository;

    public function __construct()
    {
        $this->repository = new CustomerRepository();
        $this->refreshTokenRepository = new RefreshTokenRepository();
    }

    /** GET /customers */
    public function index()
    {
        $identity = Auth::requireRole(['customer', 'admin']);
        ApiResponse::json([
            'id' => $identity['id'],
            'email' => $identity['email'],
            'first_name' => $identity['first_name'],
            'last_name' => $identity['last_name'],
            'phone' => $identity['phone'],
            'address' => $identity['address'],
            'role' => $identity['role'],
            'name' => $identity['name'],
        ], 200);
    }

    /** GET /customers/{id} */
    public function show(int $id)
    {
        $identity = Auth::identity();
        if ($identity['role'] === 'customer' && $identity['id'] !== $id) {
            ApiResponse::error('You can only access your own resource', 403);
            return;
        }

        $customer = $this->repository->find($id);

        if (!$customer) {
            ApiResponse::error('Customer not found', 404);
            return;
        }

        unset($customer['password']); // Never expose password hashes

        ApiResponse::success(["data" => $customer]);
    }

    /** POST /customers */
    public function store()
    {
        $data = Request::jsonBody();
        Validator::requireFields($data, ['first_name', 'last_name', 'email', 'password']);
        $email = Validator::email($data);
        $password = Validator::password($data);

        // register should not require OTP verification
        if ($this->repository->findByEmail($email)) {
            ApiResponse::error('Email already exists', 409);
            return;
        }

        $data['email'] = $email;
        $data['first_name'] = Validator::string($data, 'first_name', 80);
        $data['last_name'] = Validator::string($data, 'last_name', 80);
        $data['phone'] = Validator::nullableString($data, 'phone', 25);
        $data['address'] = Validator::nullableString($data, 'address', 1000);
        $data['password'] = password_hash($password, PASSWORD_DEFAULT);
        $customer = new CustomerModel($data);

        if ($this->repository->create($customer)) {
            ApiResponse::success(['message' => 'Customer created successfully'], 201);
        } else {
            ApiResponse::error('Create failed', 400);
        }
    }

       /** LOGIN */
    public function login()
    {
        $data = Request::jsonBody();
        Validator::requireFields($data, ['email', 'password']);
        $email = Validator::email($data);
        $rateKey = strtolower($email) . '|' . ClientIp::current();
        RateLimiter::ensureAllowed('customer_login', $rateKey, 5, 900, 900);

        $customerData = $this->repository->findByEmail($email);
        if (!$customerData) {
            RateLimiter::hit('customer_login', $rateKey, 5, 900, 900);
            ApiResponse::error('Invalid credentials', 401);
            return;
        }

        // verify password
        if (!password_verify((string)$data['password'], $customerData['password'])) {
            RateLimiter::hit('customer_login', $rateKey, 5, 900, 900);
            ApiResponse::error('Invalid credentials', 401);
            return;
        }

        RateLimiter::clear('customer_login', $rateKey);

        $identity = [
            'id' => $customerData['id'],
            'email' => $customerData['email'],
            'role' => 'customer',
            'first_name' => $customerData['first_name'] ?? '',
            'last_name' => $customerData['last_name'] ?? '',
            'phone' => $customerData['phone'] ?? null,
            'address' => $customerData['address'] ?? null,
            'name' => trim(($customerData['first_name'] ?? '') . ' ' . ($customerData['last_name'] ?? '')),
        ];
        $tokens = AuthSession::issueTokens($identity);

        ApiResponse::success([
            'data' => [
                ...$tokens,
                'user' => $identity,
            ],
        ]);
    }

    /** PUT /customers/{id} */
    public function update(int $id)
    {
        $identity = Auth::identity();
        if ($identity['role'] === 'customer' && $identity['id'] !== $id) {
            ApiResponse::error('You can only update your own profile', 403);
            return;
        }

        $data = Request::jsonBody();
        $existing = $this->repository->find($id);
        if (!$existing) {
            ApiResponse::error('Customer not found', 404);
            return;
        }

        if (isset($data['email'])) {
            $email = Validator::email($data);
            $emailOwner = $this->repository->findByEmail($email);
            if ($emailOwner && (int)$emailOwner['id'] !== $id) {
                ApiResponse::error('Email already exists', 409);
                return;
            }
            $data['email'] = $email;
        }

        // Security: always keep password hashed in DB.
        // If password isn't provided, preserve the existing hash.
        if (empty($data['password'])) {
            $data['password'] = $existing['password'];
        } else {
            $data['password'] = password_hash(Validator::password($data), PASSWORD_DEFAULT);
        }

        // Merge missing fields to avoid overwriting with empty strings.
        $data['first_name'] = $data['first_name'] ?? ($existing['first_name'] ?? '');
        $data['last_name'] = $data['last_name'] ?? ($existing['last_name'] ?? '');
        $data['email'] = $data['email'] ?? ($existing['email'] ?? '');
        $data['phone'] = array_key_exists('phone', $data) ? $data['phone'] : ($existing['phone'] ?? null);
        $data['address'] = array_key_exists('address', $data) ? $data['address'] : ($existing['address'] ?? null);
        $data['first_name'] = trim((string)$data['first_name']);
        $data['last_name'] = trim((string)$data['last_name']);
        $data['phone'] = $data['phone'] !== null ? trim((string)$data['phone']) : null;
        $data['address'] = $data['address'] !== null ? trim((string)$data['address']) : null;

        // Ensure email/role changes cannot escalate privileges (role is not stored for customers).
        unset($data['role']);

        $customer = new CustomerModel($data);

        if ($this->repository->update($id, $customer)) {
            ApiResponse::success(['message' => 'Customer updated']);
        } else {
            ApiResponse::error('Update failed', 400);
        }
    }

    /** DELETE /customers/{id} */
    public function delete(int $id)
    {
        $identity = Auth::identity();
        if ($identity['role'] === 'customer' && $identity['id'] !== $id) {
            ApiResponse::error('You can only delete your own account', 403);
            return;
        }

        if ($this->repository->delete($id)) {
            $this->refreshTokenRepository->deleteByCustomerId($id);
            ApiResponse::success(['message' => 'Customer deleted']);
        } else {
            ApiResponse::error('Delete failed', 400);
        }
    }

    /** POST /customers/forgot-password */
    public function forgotPassword()
    {
        try {
            $data = Request::jsonBody();
            $email = isset($data['email']) ? Validator::email($data) : '';
            $rateKey = strtolower($email) . '|' . ClientIp::current();
            RateLimiter::ensureAllowed('forgot_password', $rateKey, 3, 900, 1800);

            if (empty($email)) {
                ApiResponse::error('Email is required', 400);
                return;
            }

            $customer = $this->repository->findByEmail($email);
            if (!$customer) {
                RateLimiter::hit('forgot_password', $rateKey, 3, 900, 1800);
                ApiResponse::success([
                    'message' => 'If the account exists, a reset code has been sent.'
                ]);
                return;
            }

            $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiryMinutes = (int)($_ENV['RESET_TOKEN_EXPIRY_MINUTES'] ?? 15);
            if ($expiryMinutes <= 0) {
                $expiryMinutes = 15;
            }
            $expiry = date('Y-m-d H:i:s', strtotime("+{$expiryMinutes} minutes"));

            if (!$this->repository->saveResetToken($email, $otpCode, $expiry)) {
                ApiResponse::error('Failed to generate reset token', 500);
                return;
            }

            if (!Mailer::isSmtpConfigured()) {
                ApiResponse::error('SMTP is not configured. Cannot send OTP email.', 500);
                return;
            }

            Mailer::sendResetOtp($email, $otpCode, $expiryMinutes);
            RateLimiter::clear('forgot_password', $rateKey);
            ApiResponse::success([
                'message' => 'If the account exists, a reset code has been sent.'
            ]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    /** POST /customers/reset-password */
    public function resetPassword()
    {
        try {
            $data = Request::jsonBody();
            $token = $data['token'] ?? '';
            $newPassword = $data['new_password'] ?? '';
            $rateKey = trim((string)$token) . '|' . ClientIp::current();
            RateLimiter::ensureAllowed('reset_password', $rateKey, 5, 900, 1800);

            if (empty($token) || empty($newPassword)) {
                ApiResponse::error('Token and new password are required', 400);
                return;
            }

            if (strlen((string)$newPassword) < 8) {
                ApiResponse::error('new_password must be at least 8 characters', 422);
                return;
            }

            $customer = $this->repository->findByResetToken($token);
            if (!$customer) {
                RateLimiter::hit('reset_password', $rateKey, 5, 900, 1800);
                ApiResponse::error('Invalid or expired token', 400);
                return;
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            if ($this->repository->updatePassword($customer['id'], $hashedPassword)) {
                $this->repository->clearResetToken($customer['id']);
                $this->refreshTokenRepository->deleteByCustomerId((int)$customer['id']);
                RateLimiter::clear('reset_password', $rateKey);
                ApiResponse::success(['message' => 'Password reset successfully']);
            } else {
                ApiResponse::error('Failed to reset password', 500);
            }
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }

    //   Get Total Customers
    public function countCustomers(){
        try {
            Auth::requireRole(['admin']);
            $total = $this->repository->countCustomers();
            ApiResponse::success([
                'total_customers' => $total
            ]);
        } catch (RuntimeException $e) {
            ApiResponse::error($e->getMessage(), 500);
        }
    }
}
