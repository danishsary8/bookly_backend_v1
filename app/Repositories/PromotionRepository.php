<?php

namespace App\Repositories;

use App\Config\DatabaseConnection;
use PDO;
use PDOException;
use RuntimeException;

class PromotionRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
    }

    public function getAll(): array
    {
        $stmt = $this->db->query("
            SELECT
                id,
                code,
                description,
                discount_type,
                discount_value,
                min_subtotal,
                starts_at,
                ends_at,
                is_active,
                created_at,
                updated_at
            FROM promotions
            ORDER BY is_active DESC, created_at DESC, id DESC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $payload): array
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO promotions (
                    code,
                    description,
                    discount_type,
                    discount_value,
                    min_subtotal,
                    starts_at,
                    ends_at,
                    is_active
                ) VALUES (
                    :code,
                    :description,
                    :discount_type,
                    :discount_value,
                    :min_subtotal,
                    :starts_at,
                    :ends_at,
                    :is_active
                )
                RETURNING id
            ");
            $stmt->execute([
                'code' => strtoupper((string)$payload['code']),
                'description' => $payload['description'],
                'discount_type' => $payload['discount_type'],
                'discount_value' => $payload['discount_value'],
                'min_subtotal' => $payload['min_subtotal'],
                'starts_at' => $payload['starts_at'],
                'ends_at' => $payload['ends_at'],
                'is_active' => $payload['is_active'],
            ]);

            $id = (int)$stmt->fetchColumn();
            return $this->find($id) ?? throw new RuntimeException('Promotion not found after create');
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to create promotion: ' . $e->getMessage());
        }
    }

    public function update(int $id, array $payload): array
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE promotions
                SET
                    code = :code,
                    description = :description,
                    discount_type = :discount_type,
                    discount_value = :discount_value,
                    min_subtotal = :min_subtotal,
                    starts_at = :starts_at,
                    ends_at = :ends_at,
                    is_active = :is_active
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $id,
                'code' => strtoupper((string)$payload['code']),
                'description' => $payload['description'],
                'discount_type' => $payload['discount_type'],
                'discount_value' => $payload['discount_value'],
                'min_subtotal' => $payload['min_subtotal'],
                'starts_at' => $payload['starts_at'],
                'ends_at' => $payload['ends_at'],
                'is_active' => $payload['is_active'],
            ]);

            return $this->find($id) ?? throw new RuntimeException('Promotion not found');
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to update promotion: ' . $e->getMessage());
        }
    }

    public function delete(int $id): void
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM promotions WHERE id = :id');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Promotion not found');
            }
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to delete promotion: ' . $e->getMessage());
        }
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                id,
                code,
                description,
                discount_type,
                discount_value,
                min_subtotal,
                starts_at,
                ends_at,
                is_active,
                created_at,
                updated_at
            FROM promotions
            WHERE id = :id
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findApplicableByCode(string $code, float $subtotal): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                id,
                code,
                description,
                discount_type,
                discount_value,
                min_subtotal,
                starts_at,
                ends_at,
                is_active,
                created_at,
                updated_at
            FROM promotions
            WHERE UPPER(code) = UPPER(:code)
              AND is_active = TRUE
              AND (starts_at IS NULL OR starts_at <= CURRENT_TIMESTAMP)
              AND (ends_at IS NULL OR ends_at >= CURRENT_TIMESTAMP)
            LIMIT 1
        ");
        $stmt->execute(['code' => trim($code)]);
        $promotion = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$promotion) {
            return null;
        }

        if ($subtotal < (float)$promotion['min_subtotal']) {
            return null;
        }

        return $promotion;
    }

    public function calculateDiscountAmount(array $promotion, float $subtotal): float
    {
        $discountType = (string)$promotion['discount_type'];
        $discountValue = (float)$promotion['discount_value'];

        if ($discountType === 'percent') {
            return round(min($subtotal, $subtotal * ($discountValue / 100)), 2);
        }

        return round(min($subtotal, $discountValue), 2);
    }
}
