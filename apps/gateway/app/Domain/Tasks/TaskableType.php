<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

final readonly class TaskableType
{
    public const string AppInstance = AppInstance::class;

    public static function allows(string $type): bool
    {
        return $type === self::AppInstance;
    }
}
