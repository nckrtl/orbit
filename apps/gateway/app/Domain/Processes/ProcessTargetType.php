<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Models\Instance;
use App\Models\Node;

enum ProcessTargetType: string
{
    case Instance = 'instance';
    case Node = 'node';

    /** @return class-string<Instance|Node> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Instance => Instance::class,
            self::Node => Node::class,
        };
    }

    public function storedType(): string
    {
        return match ($this) {
            self::Instance => Instance::MorphAlias,
            self::Node => Node::class,
        };
    }

    /** @return list<string> */
    public function storedTypes(): array
    {
        return match ($this) {
            self::Instance => [Instance::MorphAlias],
            self::Node => [Node::class],
        };
    }

    public static function fromModelClass(string $modelClass): self
    {
        return match (true) {
            Instance::isMorphType($modelClass) => self::Instance,
            $modelClass === Node::class => self::Node,
            default => throw new \InvalidArgumentException('Unsupported process target model.'),
        };
    }
}
