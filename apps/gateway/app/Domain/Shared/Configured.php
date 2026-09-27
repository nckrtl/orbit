<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use RuntimeException;

/** Configuration entries whose file already stores a string or an integer. */
final class Configured
{
    public static function string(string $key, ?string $default = null): string
    {
        $value = $default === null ? config($key) : config($key, $default);

        if (! is_string($value)) {
            throw new RuntimeException("Configuration [{$key}] must be a string.");
        }

        return $value;
    }

    public static function int(string $key, ?int $default = null): int
    {
        $value = $default === null ? config($key) : config($key, $default);

        if (! is_int($value)) {
            throw new RuntimeException("Configuration [{$key}] must be an integer.");
        }

        return $value;
    }
}
