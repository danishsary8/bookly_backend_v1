<?php
namespace App\Helpers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtToken
{
    private static function secret(): string
    {
        return $_ENV['JWT_SECRET'];
    }

    private static function tokenTtlSeconds(): int
    {
        // Default: 7 days. You can override with JWT_TTL_SECONDS in .env
        $ttl = (int)($_ENV['JWT_TTL_SECONDS'] ?? 604800);
        return $ttl > 0 ? $ttl : 604800;
    }

    private static function refreshTokenTtlSeconds(): int
    {
        $ttl = (int)($_ENV['JWT_REFRESH_TTL_SECONDS'] ?? 2592000);
        return $ttl > 0 ? $ttl : 2592000;
    }

    /**
     * HEADER  : { "alg": "HS256", "typ": "JWT" }
     * PAYLOAD : user data + exp
     * SIGN    : created using JWT_SECRET
     */
    
    public static function generate(array $payload): string
    {
        $now = time();
        // Ensure tokens always include iat/exp so access expires safely.
        if (!array_key_exists('iat', $payload)) {
            $payload['iat'] = $now;
        }
        if (!array_key_exists('exp', $payload)) {
            $payload['exp'] = $now + self::tokenTtlSeconds();
        }

        return JWT::encode(
            $payload,                // Payload
            self::secret(),          // Signature key
            'HS256'                  // Algorithm (Header)
        );
    }

    public static function validate(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key(self::secret(), 'HS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function generateAccessToken(array $payload): string
    {
        $payload['token_type'] = 'access';
        return self::generate($payload);
    }

    public static function generateRefreshToken(array $payload): string
    {
        $now = time();
        $payload['token_type'] = 'refresh';
        $payload['iat'] = $now;
        $payload['exp'] = $now + self::refreshTokenTtlSeconds();
        $payload['jti'] = bin2hex(random_bytes(16));

        return JWT::encode($payload, self::secret(), 'HS256');
    }

    public static function refreshTokenExpiryDateTime(): string
    {
        return date('Y-m-d H:i:s', time() + self::refreshTokenTtlSeconds());
    }
}
