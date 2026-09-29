<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

final readonly class TaskableType
{
    public const string Instance = 'instance';

    public static function allows(string $type): bool
    {
        return Instance::isMorphType($type);
    }
}
