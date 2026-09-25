<?php

namespace App\Helpers;

use App\Repositories\AuthRateLimitRepository;

final class RateLimiter
{
    private static function repo(): AuthRateLimitRepository
    {
        return new AuthRateLimitRepository();
    }

    public static function ensureAllowed(
        string $action,
        string $identifier,
        int $maxAttempts,
        int $windowSeconds,
        int $blockSeconds,
        string $message = 'Too many attempts. Please try again later.'
    ): void {
        $record = self::repo()->find($action, $identifier);
        if (!$record) {
            return;
        }

        $now = time();
        $blockedUntil = isset($record['blocked_until']) && $record['blocked_until'] !== null
            ? strtotime((string)$record['blocked_until'])
            : null;

        if ($blockedUntil !== null && $blockedUntil > $now) {
            ApiResponse::error($message, 429);
            exit;
        }

        $windowStarted = strtotime((string)$record['window_started_at']);
        if ($windowStarted !== false && ($now - $windowStarted) >= $windowSeconds) {
            self::repo()->resetWindow((int)$record['id'], 0, null);
        }
    }

    public static function hit(
        string $action,
        string $identifier,
        int $maxAttempts,
        int $windowSeconds,
        int $blockSeconds
    ): void {
        $repo = self::repo();
        $record = $repo->find($action, $identifier);
        $blockedUntil = null;

        if (!$record) {
            $blockedUntil = $maxAttempts <= 1 ? date('Y-m-d H:i:s', time() + $blockSeconds) : null;
            $repo->create($action, $identifier, 1, $blockedUntil);
            return;
        }

        $now = time();
        $windowStarted = strtotime((string)$record['window_started_at']);
        $attempts = (int)$record['attempts'];
        if ($windowStarted === false || ($now - $windowStarted) >= $windowSeconds) {
            $attempts = 0;
            $repo->resetWindow((int)$record['id'], 0, null);
        }

        $attempts++;
        if ($attempts >= $maxAttempts) {
            $blockedUntil = date('Y-m-d H:i:s', $now + $blockSeconds);
        }

        $repo->updateAttempts((int)$record['id'], $attempts, $blockedUntil);
    }

    public static function clear(string $action, string $identifier): void
    {
        self::repo()->clear($action, $identifier);
    }
}
