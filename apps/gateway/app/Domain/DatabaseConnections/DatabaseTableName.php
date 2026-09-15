<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use App\Domain\Shared\ResourceOperationException;

final readonly class DatabaseTableName
{
    public const string PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]*\z/D';

    public const int MAX_LENGTH = 64;

    public static function normalize(string $table): string
    {
        if ($table === '' || strlen($table) > self::MAX_LENGTH || preg_match(self::PATTERN, $table) !== 1) {
            throw new ResourceOperationException(
                errorCode: 'validation.failed',
                message: 'Table name must be a bounded SQL identifier.',
            );
        }

        return $table;
    }
}
