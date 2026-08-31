<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Data\Nodes\AppsSettingsData;
use App\Data\Nodes\NodeSettingsData;
use App\Domain\Shared\ResourceOperationException;
use stdClass;

/** @mago-expect lint:cyclomatic-complexity Closed-key parsing keeps omit, null, and nested objects together. */
final readonly class NodeSettingsParser
{
    /** @var list<string> */
    private const array TOP_LEVEL_KEYS = ['apps'];

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
        $object = $this->jsonObject($value, 'The settings object is invalid.');
        $this->assertClosedKeys($object, self::TOP_LEVEL_KEYS);

        if ($object === []) {
            $this->reject('The settings object must contain at least one known member.');
        }

        return new NodeSettingsPatch(
            hasApps: array_key_exists('apps', $object),
            apps: array_key_exists('apps', $object) ? $this->nestedApps($object['apps']) : null,
        );
    }

    private function parseObject(mixed $value, bool $requiredMember): NodeSettingsData
    {
        $object = $this->jsonObject($value, 'The settings object is invalid.');
        $this->assertClosedKeys($object, self::TOP_LEVEL_KEYS);

        if ($requiredMember && $object === []) {
            $this->reject('The settings object must contain at least one known member.');
        }

        return new NodeSettingsData(
            apps: array_key_exists('apps', $object) ? $this->nestedApps($object['apps']) : null,
        );
    }

    private function nestedApps(mixed $value): ?AppsSettingsData
    {
        if ($value === null) {
            return null;
        }

        $value = $this->assertNestedObject($value);
        $path = $value['path'] ?? null;

        if ($path !== null && ! is_string($path)) {
            $this->reject('The apps path must be a string or null.');
        }

        if ($path === '') {
            throw new ResourceOperationException(
                errorCode: 'node.settings_path_invalid',
                message: 'The apps storage path is not a normalized absolute path.',
            );
        }

        return new AppsSettingsData(is_string($path) ? $path : null);
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
        $object = $this->jsonObject($value, 'Nested settings must be an object or null.');
        $this->assertClosedKeys($object, self::NESTED_KEYS);

        return $object;
    }

    /** @return array<array-key, mixed> */
    private function jsonObject(mixed $value, string $message): array
    {
        if (! $value instanceof stdClass) {
            $this->reject($message);
        }

        return get_object_vars($value);
    }

    private function reject(string $message): never
    {
        throw new ResourceOperationException(
            errorCode: 'node.settings_invalid',
            message: $message,
        );
    }
}
