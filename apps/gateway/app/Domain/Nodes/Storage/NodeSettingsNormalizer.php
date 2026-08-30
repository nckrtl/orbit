<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\InstanceSettingsData;
use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\WorktreeSettingsData;

/** @mago-expect lint:cyclomatic-complexity Nested path normalization stays in one closed DTO mapper. */
final readonly class NodeSettingsNormalizer
{
    public function normalize(?NodeSettingsData $settings): ?NodeSettingsData
    {
        if (! $settings instanceof NodeSettingsData) {
            return null;
        }

        $instance = $this->nested($settings->instancePath());
        $worktree = $this->nestedWorktree($settings->worktreePath());
        $normalized = new NodeSettingsData(
            instance: $instance,
            worktree: $worktree,
        );

        return $normalized->isEmpty() ? null : $normalized;
    }

    /** @return array<string, mixed>|null */
    public function stored(?NodeSettingsData $settings): ?array
    {
        $normalized = $this->normalize($settings);

        if (! $normalized instanceof NodeSettingsData) {
            return null;
        }

        $payload = [];
        $instancePath = $normalized->instancePath();
        $worktreePath = $normalized->worktreePath();

        if ($instancePath !== null) {
            $payload['instance'] = ['path' => $instancePath];
        }

        if ($worktreePath !== null) {
            $payload['worktree'] = ['path' => $worktreePath];
        }

        return $payload;
    }

    public function fromStored(mixed $value): ?NodeSettingsData
    {
        if (! is_array($value)) {
            return null;
        }

        return $this->normalize(new NodeSettingsData(
            instance: $this->nestedFromStored($value['instance'] ?? null),
            worktree: $this->nestedWorktreeFromStored($value['worktree'] ?? null),
        ));
    }

    private function nested(?string $path): ?InstanceSettingsData
    {
        return $path === null ? null : new InstanceSettingsData($path);
    }

    private function nestedWorktree(?string $path): ?WorktreeSettingsData
    {
        return $path === null ? null : new WorktreeSettingsData($path);
    }

    private function nestedFromStored(mixed $value): ?InstanceSettingsData
    {
        if (! is_array($value)) {
            return null;
        }

        $path = $value['path'] ?? null;

        return is_string($path) ? new InstanceSettingsData($path) : null;
    }

    private function nestedWorktreeFromStored(mixed $value): ?WorktreeSettingsData
    {
        if (! is_array($value)) {
            return null;
        }

        $path = $value['path'] ?? null;

        return is_string($path) ? new WorktreeSettingsData($path) : null;
    }
}
