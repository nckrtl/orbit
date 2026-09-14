<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use App\Domain\Shared\ResourceOperationException;

final readonly class DatabaseConnectionPrefix
{
    public const string Default = 'DB';

    public const string Pattern = '/\A[A-Z][A-Z0-9_]{0,31}\z/D';

    public static function normalize(?string $prefix): string
    {
        $value = $prefix ?? self::Default;

        if (preg_match(self::Pattern, $value) !== 1) {
            throw new ResourceOperationException(
                errorCode: 'validation.failed',
                message: 'Prefix must be an uppercase name of at most 32 characters.',
                status: 422,
            );
        }

        return $value;
    }
}
