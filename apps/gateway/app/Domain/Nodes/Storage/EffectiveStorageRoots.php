<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

final readonly class EffectiveStorageRoots
{
    public function __construct(
        public StoragePath $instance,
        public StoragePath $worktree,
    ) {}
}
