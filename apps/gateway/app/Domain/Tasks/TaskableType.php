<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\Instance;

final readonly class TaskableType
{
    public const string Instance = 'instance';

    public const string AppInstance = AppInstance::class;

    public static function allows(string $type): bool
    {
        return in_array($type, [self::Instance, self::AppInstance, Instance::class], true);
    }
}
