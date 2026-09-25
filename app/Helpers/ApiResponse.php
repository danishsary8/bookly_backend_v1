<?php

namespace App\Helpers;

final class ApiResponse
{
    public static function json(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        echo json_encode($payload);
    }

    public static function success(array $data = [], int $statusCode = 200): void
    {
        self::json([
            'status' => 'success',
            ...$data,
        ], $statusCode);
    }

    public static function error(string $message, int $statusCode = 400, array $extra = []): void
    {
        self::json([
            'status' => 'error',
            'message' => $message,
            ...$extra,
        ], $statusCode);
    }
}
