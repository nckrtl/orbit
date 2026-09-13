<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Models\AppInstance;
use App\Models\Node;

enum ProcessTargetType: string
{
    case AppInstance = 'instance';
    case Node = 'node';

    /** @return class-string<AppInstance|Node> */
    public function modelClass(): string
    {
        return match ($this) {
            self::AppInstance => AppInstance::class,
            self::Node => Node::class,
        };
    }

    public static function fromModelClass(string $modelClass): self
    {
        return match ($modelClass) {
            AppInstance::class => self::AppInstance,
            Node::class => self::Node,
            default => throw new \InvalidArgumentException('Unsupported process target model.'),
        };
    }
}
