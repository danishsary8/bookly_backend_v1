<?php

namespace App\Helpers;

use Throwable;

final class ErrorHandler
{
    public static function register(): void
    {
        ini_set('display_errors', '0');

        set_exception_handler(static function (Throwable $exception): void {
            ApiResponse::error(
                self::isProduction() ? 'Internal server error' : $exception->getMessage(),
                500
            );
        });

        set_error_handler(static function (
            int $severity,
            string $message,
            string $file = '',
            int $line = 0
        ): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error === null) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
            if (!in_array($error['type'], $fatalTypes, true)) {
                return;
            }

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json');
            }

            echo json_encode([
                'status' => 'error',
                'message' => self::isProduction() ? 'Internal server error' : $error['message'],
            ]);
        });
    }

    private static function isProduction(): bool
    {
        return strtolower((string)($_ENV['APP_ENV'] ?? 'development')) === 'production';
    }
}
