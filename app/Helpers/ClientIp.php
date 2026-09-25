<?php

namespace App\Helpers;

final class ClientIp
{
    public static function current(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['HTTP_CLIENT_IP'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!$candidate) {
                continue;
            }

            $ip = trim(explode(',', (string)$candidate)[0]);
            if ($ip !== '') {
                return $ip;
            }
        }

        return 'unknown';
    }
}
