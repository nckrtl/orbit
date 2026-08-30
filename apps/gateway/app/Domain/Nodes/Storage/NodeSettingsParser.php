<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\InstanceSettingsData;
use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\WorktreeSettingsData;
use App\Domain\Shared\ResourceOperationException;

/** @mago-expect lint:cyclomatic-complexity Closed-key parsing keeps omit, null, and nested objects together. */
final readonly class NodeSettingsParser
{
    /** @var list<string> */
    private const array TOP_LEVEL_KEYS = ['instance', 'worktree'];

    /** @var list<string> */
    private const array NESTED_KEYS = ['path'];

    public function parseComplete(mixed $value): ?NodeSettingsData
    {
        if ($value === null) {
            return null;
        }

        return $this->parseObject($value, requiredMember: false);
    }

    public function parsePatch(mixed $value): NodeSettingsPatch
    {
        if (! is_array($value)) {
            $this->reject('The settings object is invalid.');
        }

        $this->assertClosedKeys($value, self::TOP_LEVEL_KEYS);

        if ($value === []) {
            $this->reject('The settings object must contain at least one known member.');
        }

        return new NodeSettingsPatch(
            hasInstance: array_key_exists('instance', $value),
            instance: array_key_exists('instance', $value) ? $this->nestedInstance($value['instance']) : null,
            hasWorktree: array_key_exists('worktree', $value),
            worktree: array_key_exists('worktree', $value) ? $this->nestedWorktree($value['worktree']) : null,
        );
    }

    private function parseObject(mixed $value, bool $requiredMember): NodeSettingsData
    {
        if (! is_array($value)) {
            $this->reject('The settings object is invalid.');
        }

        $this->assertClosedKeys($value, self::TOP_LEVEL_KEYS);

        if ($requiredMember && $value === []) {
            $this->reject('The settings object must contain at least one known member.');
        }

        return new NodeSettingsData(
            instance: array_key_exists('instance', $value) ? $this->nestedInstance($value['instance']) : null,
            worktree: array_key_exists('worktree', $value) ? $this->nestedWorktree($value['worktree']) : null,
        );
    }

    private function nestedInstance(mixed $value): ?InstanceSettingsData
    {
        if ($value === null) {
            return null;
        }

        $this->assertNestedObject($value);

        $path = $value['path'] ?? null;

        if ($path !== null && ! is_string($path)) {
            $this->reject('The instance path must be a string or null.');
        }

        return new InstanceSettingsData(is_string($path) ? $path : null);
    }

    private function nestedWorktree(mixed $value): ?WorktreeSettingsData
    {
        if ($value === null) {
            return null;
        }

        $this->assertNestedObject($value);

        $path = $value['path'] ?? null;

        if ($path !== null && ! is_string($path)) {
            $this->reject('The worktree path must be a string or null.');
        }

        return new WorktreeSettingsData(is_string($path) ? $path : null);
    }

    /** @param array<array-key, mixed> $value */
    private function assertClosedKeys(array $value, array $allowed): void
    {
        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, strict: true)) {
                $this->reject('The settings object contains unsupported keys.');
            }
        }
    }

    /** @return array<array-key, mixed> */
    private function assertNestedObject(mixed $value): array
    {
        if (! is_array($value)) {
            $this->reject('Nested settings must be an object or null.');
        }

        $this->assertClosedKeys($value, self::NESTED_KEYS);

        return $value;
    }

    private function reject(string $message): never
    {
        throw new ResourceOperationException(
            errorCode: 'node.settings_invalid',
            message: $message,
        );
    }
}
