<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared;

/** Normalizes values loaded from untyped configuration, JSON, or database drivers. */
final class StoredValue
{
    public static function integer(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);

            if (is_int($parsed)) {
                return $parsed;
            }
        }

        return $default;
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }
}
