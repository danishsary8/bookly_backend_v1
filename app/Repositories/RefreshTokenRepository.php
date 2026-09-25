<?php
namespace App\Repositories;

use App\Config\DatabaseConnection;
use PDO;

class RefreshTokenRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
    }

    public function create(int $customerId, string $tokenHash, string $jwtId, string $expiresAt): bool
    {
        $sql = "INSERT INTO refresh_tokens (customer_id, token_hash, jwt_id, expires_at)
                VALUES (:customer_id, :token_hash, :jwt_id, :expires_at)";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            'customer_id' => $customerId,
            'token_hash' => $tokenHash,
            'jwt_id' => $jwtId,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findValid(string $tokenHash, string $jwtId): ?array
    {
        $sql = "SELECT * FROM refresh_tokens
                WHERE token_hash = :token_hash
                  AND jwt_id = :jwt_id
                  AND expires_at > NOW()
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'token_hash' => $tokenHash,
            'jwt_id' => $jwtId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function deleteById(int $id): bool
    {
        $sql = "DELETE FROM refresh_tokens WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function deleteByTokenHash(string $tokenHash): bool
    {
        $sql = "DELETE FROM refresh_tokens WHERE token_hash = :token_hash";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['token_hash' => $tokenHash]);
    }

    public function deleteByCustomerId(int $customerId): bool
    {
        $sql = "DELETE FROM refresh_tokens WHERE customer_id = :customer_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['customer_id' => $customerId]);
    }
}
