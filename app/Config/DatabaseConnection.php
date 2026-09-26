<?php

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

final class DatabaseConnection
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        try {
            $sslMode = strtolower((string)($_ENV['DB_SSLMODE'] ??
                (($_ENV['APP_ENV'] ?? '') === 'production' ? 'require' : 'prefer')));
            $channelBinding = strtolower((string)($_ENV['DB_CHANNEL_BINDING'] ?? 'prefer'));

            if (!in_array($sslMode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)
                || !in_array($channelBinding, ['disable', 'prefer', 'require'], true)) {
                throw new RuntimeException('Invalid PostgreSQL TLS configuration');
            }

            // PDO_PGSQL accepts sslmode in its DSN but does not accept
            // channel_binding there. libpq reads PGCHANNELBINDING instead.
            putenv('PGCHANNELBINDING=' . $channelBinding);

            $dsn = sprintf(
                "%s:host=%s;port=%s;dbname=%s;sslmode=%s",
                $_ENV['DB_DRIVER'],
                $_ENV['DB_HOST'],
                $_ENV['DB_PORT'],
                $_ENV['DB_NAME'],
                $sslMode
            );

            self::$instance = new PDO(
                $dsn,
                $_ENV['DB_USER'],
                $_ENV['DB_PASS'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );

            return self::$instance;

        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }
}
