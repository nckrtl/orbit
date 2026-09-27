<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** The operator's Orbit home directory from configuration. */
final class OrbitHome
{
    public static function path(): string
    {
        $home = config('orbit.home');

        if (! is_string($home) || $home === '') {
            throw new RuntimeException('Orbit home is not configured.');
        }

        return rtrim($home, '/');
    }
}
