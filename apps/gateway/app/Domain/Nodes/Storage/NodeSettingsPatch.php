<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\InstanceSettingsData;
use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\WorktreeSettingsData;

final readonly class NodeSettingsPatch
{
    public function __construct(
        public bool $hasInstance,
        public ?InstanceSettingsData $instance,
        public bool $hasWorktree,
        public ?WorktreeSettingsData $worktree,
    ) {}

    public function merge(?NodeSettingsData $stored): NodeSettingsData
    {
        $current = $stored ?? new NodeSettingsData;

        return new NodeSettingsData(
            instance: $this->hasInstance ? $this->instance : $current->instance,
            worktree: $this->hasWorktree ? $this->worktree : $current->worktree,
        );
    }

    public function isEmpty(): bool
    {
        return ! $this->hasInstance && ! $this->hasWorktree;
    }
}
