<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use Spatie\LaravelData\Data;

final class NodeSettingsData extends Data
{
    public function __construct(
        public ?InstanceSettingsData $instance = null,
        public ?WorktreeSettingsData $worktree = null,
    ) {}

    public function instancePath(): ?string
    {
        $path = $this->instance?->path;

        return is_string($path) && $path !== '' ? $path : null;
    }

    public function worktreePath(): ?string
    {
        $path = $this->worktree?->path;

        return is_string($path) && $path !== '' ? $path : null;
    }

    public function isEmpty(): bool
    {
        return $this->instancePath() === null && $this->worktreePath() === null;
    }
}
