<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Models\AppInstance;
use App\Models\Node;

enum ScheduleTargetType: string
{
    case Node = 'node';
    case AppInstance = 'instance';

    /** @return class-string<Node|AppInstance> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Node => Node::class,
            self::AppInstance => AppInstance::class,
        };
    }
}
