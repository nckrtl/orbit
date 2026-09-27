<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final class SerializedArrays
{
    /** @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Serialized object keys must be strings.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array{path: string, content_sha256: string, mode: int, filesystem_type: string}
     */
    public static function freezeEvidence(array $value): array
    {
        $value = self::stringKeyed($value);
        if (
            array_keys($value) !== ['path', 'content_sha256', 'mode', 'filesystem_type']
            || ! is_string($value['path'])
            || ! is_string($value['content_sha256'])
            || ! is_int($value['mode'])
            || ! is_string($value['filesystem_type'])
        ) {
            throw new InvalidArgumentException('Serialized freeze evidence is invalid.');
        }

        return [
            'path' => $value['path'],
            'content_sha256' => $value['content_sha256'],
            'mode' => $value['mode'],
            'filesystem_type' => $value['filesystem_type'],
        ];
    }

    /** @param array<array-key, mixed> $value
     * @return list<mixed>
     */
    public static function list(array $value): array
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException('Serialized list values must be lists.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $value
     * @return list<string>
     */
    public static function stringList(array $value): array
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException('Serialized string values must be a list.');
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('Serialized list values must be strings.');
            }
        }

        return $value;
    }

    /** @param array<array-key, mixed> $value
     * @return list<array<string, mixed>>
     */
    public static function recordList(array $value): array
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException('Serialized record values must be a list.');
        }

        $records = [];
        foreach ($value as $record) {
            if (! is_array($record)) {
                throw new InvalidArgumentException('Serialized records must be objects.');
            }
            $records[] = self::stringKeyed($record);
        }

        return $records;
    }

    /** @param array<array-key, mixed> $value
     * @return array<string, list<array<string, mixed>>>
     */
    public static function recordGroups(array $value): array
    {
        $groups = [];
        foreach (self::stringKeyed($value) as $key => $records) {
            if (! is_array($records)) {
                throw new InvalidArgumentException('Serialized record groups must be lists.');
            }
            $groups[$key] = self::recordList($records);
        }

        return $groups;
    }

    /** @param array<array-key, mixed> $value
     * @return array<string, array<string, mixed>>
     */
    public static function maps(array $value): array
    {
        $result = [];
        foreach (self::stringKeyed($value) as $key => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Serialized nested values must be objects.');
            }
            $result[$key] = self::stringKeyed($item);
        }

        return $result;
    }

    /** @param array<array-key, mixed> $value
     * @return array<string, bool>
     */
    public static function boolMap(array $value): array
    {
        $result = [];
        foreach (self::stringKeyed($value) as $key => $item) {
            if (! is_bool($item)) {
                throw new InvalidArgumentException('Serialized map values must be booleans.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<array-key, mixed> $value
     * @return array<string, list<string>>
     */
    public static function stringLists(array $value): array
    {
        $result = [];
        foreach (self::stringKeyed($value) as $key => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Serialized map values must be lists.');
            }
            $result[$key] = self::stringList($item);
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array{static_classification: bool, proof_contract: bool, checkout_literals: bool, observed_processes: bool, observed_paths: bool, pcov_cleanup: bool}
     */
    public static function proofCompleteness(array $value): array
    {
        $value = self::stringKeyed($value);
        $keys = [
            'static_classification', 'proof_contract', 'checkout_literals', 'observed_processes',
            'observed_paths', 'pcov_cleanup',
        ];
        if (array_keys($value) !== $keys) {
            throw new InvalidArgumentException('Serialized proof completeness is invalid.');
        }
        foreach ($keys as $key) {
            if (! is_bool($value[$key])) {
                throw new InvalidArgumentException('Serialized proof completeness is invalid.');
            }
        }

        return [
            'static_classification' => $value['static_classification'],
            'proof_contract' => $value['proof_contract'],
            'checkout_literals' => $value['checkout_literals'],
            'observed_processes' => $value['observed_processes'],
            'observed_paths' => $value['observed_paths'],
            'pcov_cleanup' => $value['pcov_cleanup'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array{remote: string, project: string, pool: string}|array{remote: string, project: string, pool: string, topology_snapshot_namespace: string}
     */
    public static function legacyScope(array $value, int $schema): array
    {
        $scope = self::stringKeyed($value);
        $keys = $schema === 1
            ? ['remote', 'project', 'pool', 'topology_snapshot_namespace']
            : ['remote', 'project', 'pool'];
        if (
            array_keys($scope) !== $keys
            || ! is_string($scope['remote'] ?? null)
            || ! is_string($scope['project'] ?? null)
            || ! is_string($scope['pool'] ?? null)
            || $schema === 1
            && (! is_string($scope['topology_snapshot_namespace'] ?? null)
                || $scope['topology_snapshot_namespace'] !== '')
        ) {
            throw new InvalidArgumentException('The legacy topology snapshot inventory is invalid.');
        }
        if ($schema === 1) {
            return [
                'remote' => $scope['remote'],
                'project' => $scope['project'],
                'pool' => $scope['pool'],
                'topology_snapshot_namespace' => $scope['topology_snapshot_namespace'],
            ];
        }

        return ['remote' => $scope['remote'], 'project' => $scope['project'], 'pool' => $scope['pool']];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, list<array{name: string, created_at: string}>>
     */
    public static function snapshotGroups(array $value): array
    {
        $result = [];
        foreach (self::stringKeyed($value) as $key => $items) {
            if (! is_array($items) || ! array_is_list($items)) {
                throw new InvalidArgumentException('Serialized snapshot groups are invalid.');
            }
            $snapshots = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    throw new InvalidArgumentException('Serialized snapshot entries are invalid.');
                }
                $snapshot = self::stringKeyed($item);
                if (
                    array_keys($snapshot) !== ['name', 'created_at']
                    || ! is_string($snapshot['name'])
                    || ! is_string($snapshot['created_at'])
                ) {
                    throw new InvalidArgumentException('Serialized snapshot entries are invalid.');
                }
                $snapshots[] = ['name' => $snapshot['name'], 'created_at' => $snapshot['created_at']];
            }
            $result[$key] = $snapshots;
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, array{device: string, source: string, path: string}>
     */
    public static function mounts(array $value): array
    {
        $result = [];
        foreach (self::stringKeyed($value) as $key => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Serialized mount values must be objects.');
            }
            $mount = self::stringKeyed($item);
            if (
                array_keys($mount) !== ['device', 'source', 'path']
                || ! is_string($mount['device'])
                || ! is_string($mount['source'])
                || ! is_string($mount['path'])
            ) {
                throw new InvalidArgumentException('Serialized mount values are invalid.');
            }
            $result[$key] = [
                'device' => $mount['device'],
                'source' => $mount['source'],
                'path' => $mount['path'],
            ];
        }

        return $result;
    }

    /** @param array<array-key, mixed> $value
     * @return array<string, string>
     */
    public static function stringMap(array $value): array
    {
        $result = [];
        foreach (self::stringKeyed($value) as $key => $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('Serialized map values must be strings.');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
