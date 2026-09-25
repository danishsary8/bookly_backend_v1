<?php

namespace App\Helpers;

final class Validator
{
    public static function requireFields(array $data, array $fields): void
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || self::isBlank($data[$field])) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            ApiResponse::error('Validation failed', 422, ['errors' => [
                'missing_fields' => $missing,
            ]]);
            exit;
        }
    }

    public static function string(array $data, string $field, int $maxLength = 255, bool $required = true): ?string
    {
        $value = $data[$field] ?? null;
        if ($value === null) {
            if ($required) {
                self::requireFields($data, [$field]);
            }
            return null;
        }

        $value = trim((string)$value);
        if ($value === '') {
            if ($required) {
                ApiResponse::error("{$field} is required", 422);
                exit;
            }
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            ApiResponse::error("{$field} exceeds max length of {$maxLength}", 422);
            exit;
        }

        return $value;
    }

    public static function nullableString(array $data, string $field, int $maxLength = 255): ?string
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }

        $value = trim((string)$data[$field]);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            ApiResponse::error("{$field} exceeds max length of {$maxLength}", 422);
            exit;
        }

        return $value;
    }

    /**
     * Validate an optional image URL while retaining support for existing
     * relative legacy filenames. Cloudinary URLs are stored exactly as pasted.
     */
    public static function imageUrl(array $data, string $field = 'book_img', int $maxLength = 2000): ?string
    {
        $value = self::nullableString($data, $field, $maxLength);
        if ($value === null) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) === false) {
            ApiResponse::error("{$field} must be a valid HTTP or HTTPS URL", 422);
            exit;
        }

        return $value;
    }

    public static function email(array $data, string $field = 'email'): string
    {
        $email = self::string($data, $field, 180);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ApiResponse::error("{$field} must be a valid email address", 422);
            exit;
        }

        return strtolower($email);
    }

    public static function password(array $data, string $field = 'password', int $minLength = 8): string
    {
        $password = self::string($data, $field, 255);
        if (strlen($password) < $minLength) {
            ApiResponse::error("{$field} must be at least {$minLength} characters", 422);
            exit;
        }

        return $password;
    }

    public static function int(array $data, string $field, int $min = PHP_INT_MIN, ?int $max = null): int
    {
        if (!array_key_exists($field, $data) || filter_var($data[$field], FILTER_VALIDATE_INT) === false) {
            ApiResponse::error("{$field} must be an integer", 422);
            exit;
        }

        $value = (int)$data[$field];
        if ($value < $min || ($max !== null && $value > $max)) {
            $message = $max !== null
                ? "{$field} must be between {$min} and {$max}"
                : "{$field} must be at least {$min}";
            ApiResponse::error($message, 422);
            exit;
        }

        return $value;
    }

    public static function float(array $data, string $field, float $min = 0): float
    {
        if (!array_key_exists($field, $data) || !is_numeric($data[$field])) {
            ApiResponse::error("{$field} must be numeric", 422);
            exit;
        }

        $value = (float)$data[$field];
        if ($value < $min) {
            ApiResponse::error("{$field} must be at least {$min}", 422);
            exit;
        }

        return $value;
    }

    public static function oneOf(array $data, string $field, array $allowed, string $default = null): string
    {
        $value = $data[$field] ?? $default;
        $value = trim((string)$value);

        if ($value === '' || !in_array($value, $allowed, true)) {
            ApiResponse::error("{$field} must be one of: " . implode(', ', $allowed), 422);
            exit;
        }

        return $value;
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
