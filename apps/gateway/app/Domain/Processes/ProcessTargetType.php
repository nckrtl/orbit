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

    public function storedType(): string
    {
        return match ($this) {
            self::AppInstance => AppInstance::MorphAlias,
            self::Node => Node::class,
        };
    }

    /** @return list<string> */
    public function storedTypes(): array
    {
        return match ($this) {
            self::AppInstance => AppInstance::morphTypes(),
            self::Node => [Node::class],
        };
    }

    public static function fromModelClass(string $modelClass): self
    {
        return match (true) {
            AppInstance::isMorphType($modelClass) => self::AppInstance,
            $modelClass === Node::class => self::Node,
            default => throw new \InvalidArgumentException('Unsupported process target model.'),
        };
    }
}
