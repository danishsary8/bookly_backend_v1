<?php

namespace App\Helpers;

use App\Repositories\AdminRefreshTokenRepository;
use App\Repositories\AdminRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\RefreshTokenRepository;

final class AuthSession
{
    private static function buildCustomerIdentity(int $customerId): array
    {
        $customer = (new CustomerRepository())->find($customerId);
        if (!$customer) {
            ApiResponse::error('Customer not found', 404);
            exit;
        }

        return [
            'id' => (int)$customer['id'],
            'email' => (string)$customer['email'],
            'role' => 'customer',
            'first_name' => $customer['first_name'] ?? '',
            'last_name' => $customer['last_name'] ?? '',
            'phone' => $customer['phone'] ?? null,
            'address' => $customer['address'] ?? null,
            'name' => trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')),
        ];
    }

    private static function refreshTokenRepositoryForRole(string $role): RefreshTokenRepository|AdminRefreshTokenRepository
    {
        return $role === 'admin'
            ? new AdminRefreshTokenRepository()
            : new RefreshTokenRepository();
    }

    private static function buildAdminIdentity(int $adminId): array
    {
        $admin = (new AdminRepository())->find($adminId);
        if (!$admin) {
            ApiResponse::error('Admin not found', 404);
            exit;
        }

        return [
            'id' => (int)$admin['id'],
            'email' => (string)$admin['email'],
            'role' => 'admin',
            'first_name' => $admin['first_name'] ?? '',
            'last_name' => $admin['last_name'] ?? '',
            'phone' => $admin['phone'] ?? null,
            'address' => $admin['address'] ?? null,
            'name' => trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? '')),
        ];
    }

    public static function issueTokens(array $identity, bool $persistRefreshToken = true): array
    {
        $accessToken = JwtToken::generateAccessToken($identity);
        $response = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => (int)($_ENV['JWT_TTL_SECONDS'] ?? 604800),
            'user' => $identity,
        ];

        if (!$persistRefreshToken) {
            return $response;
        }

        $refreshToken = JwtToken::generateRefreshToken([
            'id' => $identity['id'],
            'email' => $identity['email'],
            'role' => $identity['role'],
        ]);

        $refreshClaims = JwtToken::validate($refreshToken);
        if (!$refreshClaims || empty($refreshClaims['jti'])) {
            ApiResponse::error('Failed to issue refresh token', 500);
            exit;
        }

        $repository = new RefreshTokenRepository();
        if (($identity['role'] ?? '') === 'admin') {
            (new AdminRefreshTokenRepository())->create(
                (int)$identity['id'],
                hash('sha256', $refreshToken),
                (string)$refreshClaims['jti'],
                JwtToken::refreshTokenExpiryDateTime()
            );
        } else {
            $repository->create(
                (int)$identity['id'],
                hash('sha256', $refreshToken),
                (string)$refreshClaims['jti'],
                JwtToken::refreshTokenExpiryDateTime()
            );
        }

        $response['refresh_token'] = $refreshToken;
        return $response;
    }

    public static function revokeRefreshToken(string $refreshToken, array $actorIdentity): void
    {
        $claims = JwtToken::validate($refreshToken);
        if (!$claims || ($claims['token_type'] ?? null) !== 'refresh') {
            ApiResponse::error('Invalid or expired refresh token', 401);
            exit;
        }

        if ((int)($claims['id'] ?? 0) !== (int)$actorIdentity['id'] || (string)($claims['role'] ?? '') !== (string)$actorIdentity['role']) {
            ApiResponse::error('Refresh token does not belong to the authenticated user', 403);
            exit;
        }

        self::refreshTokenRepositoryForRole((string)$claims['role'])->deleteByTokenHash(hash('sha256', $refreshToken));
    }

    public static function refreshAccessToken(string $refreshToken): array
    {
        $claims = JwtToken::validate($refreshToken);
        if (!$claims || ($claims['token_type'] ?? null) !== 'refresh') {
            ApiResponse::error('Invalid or expired refresh token', 401);
            exit;
        }

        $role = (string)($claims['role'] ?? '');
        $repository = self::refreshTokenRepositoryForRole($role);
        $stored = $repository->findValid(
            hash('sha256', $refreshToken),
            (string)($claims['jti'] ?? '')
        );

        if (!$stored) {
            ApiResponse::error('Refresh token has been revoked', 401);
            exit;
        }

        $repository->deleteById((int)$stored['id']);

        if ($role === 'customer') {
            return self::issueTokens(self::buildCustomerIdentity((int)$claims['id']));
        }

        if ($role === 'admin') {
            return self::issueTokens(self::buildAdminIdentity((int)$claims['id']));
        }

        ApiResponse::error('Unsupported refresh token role', 403);
        exit;
    }
}
