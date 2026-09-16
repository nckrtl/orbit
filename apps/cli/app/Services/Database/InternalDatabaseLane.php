<?php

declare(strict_types=1);

namespace App\Services\Database;

final readonly class InternalDatabaseLane
{
    public const string TOKEN_ENV = 'ORBIT_INTERNAL_DATABASE_TOKEN';

    public static function assertToken(string $provided): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $provided) !== 1) {
            throw new LocalDatabaseQueryException(
                'database.internal_unauthorized',
                'Internal database query is unauthorized.',
            );
        }

        $expected = getenv(self::TOKEN_ENV);

        if (! is_string($expected) || $expected === '') {
            return;
        }

        if (! hash_equals($expected, $provided)) {
            throw new LocalDatabaseQueryException(
                'database.internal_unauthorized',
                'Internal database query is unauthorized.',
            );
        }
    }
}
