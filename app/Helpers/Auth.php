<?php

namespace App\Helpers;

class Auth
{
    private static function unauthorized(string $message = 'Unauthorized'): void
    {
        ApiResponse::error($message, 401);
        exit;
    }

    private static function forbidden(string $message = 'Access denied'): void
    {
        ApiResponse::error($message, 403);
        exit;
    }

    private static function bearerToken(): ?string
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if ($auth) {
            return $auth;
        }

        // Fallback for environments where HTTP_AUTHORIZATION is missing
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $key => $value) {
                if (strtolower((string)$key) === 'authorization') {
                    return (string)$value;
                }
            }
        }

        return null;
    }

    public static function identity(): array
    {
        $authHeader = self::bearerToken();
        if (!$authHeader || !preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
            self::unauthorized('Missing bearer token');
        }

        $token = trim($matches[1]);
        $decoded = JwtToken::validate($token);
        if (!$decoded) {
            self::unauthorized('Invalid or expired token');
        }

        if (empty($decoded['id']) || empty($decoded['email']) || empty($decoded['role'])) {
            // Fail closed: role-based authorization requires `role` in the token.
            self::unauthorized('Token missing required claims');
        }

        if (($decoded['token_type'] ?? 'access') !== 'access') {
            self::unauthorized('Invalid access token');
        }

        return [
            'id' => (int)$decoded['id'],
            'email' => (string)$decoded['email'],
            'role' => (string)$decoded['role'],
            'name' => isset($decoded['name']) ? (string)$decoded['name'] : null,
            'first_name' => isset($decoded['first_name']) ? (string)$decoded['first_name'] : null,
            'last_name' => isset($decoded['last_name']) ? (string)$decoded['last_name'] : null,
            'phone' => isset($decoded['phone']) ? $decoded['phone'] : null,
            'address' => isset($decoded['address']) ? $decoded['address'] : null,
            'jti' => isset($decoded['jti']) ? (string)$decoded['jti'] : null,
        ];
    }

    /**
     * @param string[] $allowedRoles
     */
    public static function requireRole(array $allowedRoles): array
    {
        $identity = self::identity();
        if (!in_array($identity['role'], $allowedRoles, true)) {
            self::forbidden('Access denied');
        }
        return $identity;
    }

    public static function requireCustomerOwnership(int $customerId): array
    {
        $identity = self::identity();
        if ($identity['role'] !== 'customer') {
            // Admin can be handled by passing allowedRoles instead.
            self::forbidden('Access denied');
        }
        if ($identity['id'] !== $customerId) {
            self::forbidden('You can only access your own resource');
        }
        return $identity;
    }
}

