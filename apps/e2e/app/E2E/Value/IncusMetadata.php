<?php

declare(strict_types=1);

namespace App\E2E\Value;

final class IncusMetadata
{
    /** Additional creation metadata cannot replace the harness ownership marker. */
    public static function isValidAdditionalMap(array $metadata): bool
    {
        return array_all(
            $metadata,
            fn ($value, $key) => ! (
                ! is_string($key)
                || ! is_string($value)
                || preg_match('/\Auser\.orbit\.e2e\.[a-z0-9.-]+\z/D', $key) !== 1
                || $key === 'user.orbit.e2e.owner'
                || str_contains($value, "\0")
            ),
        );
    }
}
