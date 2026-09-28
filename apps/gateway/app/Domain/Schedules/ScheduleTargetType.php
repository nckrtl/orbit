<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Models\Instance;
use App\Models\Node;

enum ScheduleTargetType: string
{
    case Node = 'node';
    case Instance = 'instance';

    /** @return class-string<Node|Instance> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Node => Node::class,
            self::Instance => Instance::class,
        };
    }

    public function storedType(): string
    {
        return match ($this) {
            self::Node => Node::class,
            self::Instance => Instance::MorphAlias,
        };
    }

    /** @return list<string> */
    public function storedTypes(): array
    {
        return match ($this) {
            self::Node => [Node::class],
            self::Instance => [Instance::MorphAlias],
        };
    }
}
