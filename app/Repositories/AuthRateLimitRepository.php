<?php

namespace App\Repositories;

use App\Config\DatabaseConnection;
use PDO;

class AuthRateLimitRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
    }

    public function find(string $action, string $identifier): ?array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM auth_rate_limits
            WHERE action = :action AND identifier = :identifier
            LIMIT 1
        ");
        $stmt->execute([
            'action' => $action,
            'identifier' => $identifier,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(string $action, string $identifier, int $attempts, ?string $blockedUntil): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO auth_rate_limits (action, identifier, attempts, window_started_at, blocked_until)
            VALUES (:action, :identifier, :attempts, NOW(), :blocked_until)
        ");
        return $stmt->execute([
            'action' => $action,
            'identifier' => $identifier,
            'attempts' => $attempts,
            'blocked_until' => $blockedUntil,
        ]);
    }

    public function resetWindow(int $id, int $attempts, ?string $blockedUntil): bool
    {
        $stmt = $this->db->prepare("
            UPDATE auth_rate_limits
            SET attempts = :attempts,
                window_started_at = NOW(),
                blocked_until = :blocked_until,
                updated_at = NOW()
            WHERE id = :id
        ");
        return $stmt->execute([
            'id' => $id,
            'attempts' => $attempts,
            'blocked_until' => $blockedUntil,
        ]);
    }

    public function updateAttempts(int $id, int $attempts, ?string $blockedUntil): bool
    {
        $stmt = $this->db->prepare("
            UPDATE auth_rate_limits
            SET attempts = :attempts,
                blocked_until = :blocked_until,
                updated_at = NOW()
            WHERE id = :id
        ");
        return $stmt->execute([
            'id' => $id,
            'attempts' => $attempts,
            'blocked_until' => $blockedUntil,
        ]);
    }

    public function clear(string $action, string $identifier): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM auth_rate_limits
            WHERE action = :action AND identifier = :identifier
        ");
        return $stmt->execute([
            'action' => $action,
            'identifier' => $identifier,
        ]);
    }
}
