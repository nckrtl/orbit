<?php

declare(strict_types=1);

namespace App\E2E\Value;

use App\E2E\StringKeyedMap;

final readonly class LegacyTopologySnapshotInventory
{
    /**
     * @param  array{remote:string,project:string,pool:string}|array{remote:string,project:string,pool:string,topology_snapshot_namespace:string}  $scope
     * @param  array<string, mixed>  $promotedManifest
     * @param  list<array<string, mixed>>  $recordedManifests
     * @param  array<string, array<string, mixed>>  $instances
     * @param  array<string, list<array{name:string,created_at:string}>>  $snapshots
     * @param  array<string, mixed>|null  $network
     */
    public function __construct(
        public array $scope,
        public array $promotedManifest,
        public array $recordedManifests,
        public array $instances,
        public array $snapshots,
        public ?array $network,
        public int $schema = 2,
    ) {
        self::validateScope($scope, $schema);
    }

    /** @return list<string> */
    public function resourceNames(): array
    {
        $names = array_keys($this->instances);
        if ($this->network !== null) {
            $name = $this->network['name'] ?? null;
            if (! is_string($name)) {
                throw new \InvalidArgumentException('The legacy topology snapshot inventory is invalid.');
            }
            $names[] = $name;
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => $this->schema,
            'scope' => $this->scope,
            'promoted_manifest' => $this->promotedManifest,
            'recorded_manifests' => $this->recordedManifests,
            'instances' => $this->instances,
            'snapshots' => $this->snapshots,
            'network' => $this->network,
        ];
    }

    public function sha256(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== [
                'schema',
                'scope',
                'promoted_manifest',
                'recorded_manifests',
                'instances',
                'snapshots',
                'network',
            ]
            || ! in_array($value['schema'] ?? null, [1, 2], true)
            || ! is_array($value['scope'])
            || ! is_array($value['promoted_manifest'])
            || array_is_list($value['promoted_manifest'])
            || ! is_array($value['recorded_manifests'])
            || ! array_is_list($value['recorded_manifests'])
            || ! array_all($value['recorded_manifests'], static fn (mixed $item): bool => is_array($item))
            || ! is_array($value['instances'])
            || ! self::isMap($value['instances'])
            || ! is_array($value['snapshots'])
            || ! self::isMap($value['snapshots'])
            || $value['network'] !== null
            && (! is_array($value['network'])
            || array_is_list($value['network']))
        ) {
            throw new \InvalidArgumentException('The legacy topology snapshot inventory is invalid.');
        }

        $schema = $value['schema'];
        $scope = SerializedArrays::legacyScope($value['scope'], $schema);
        $promotedManifest = SerializedArrays::stringKeyed($value['promoted_manifest']);
        $recordedManifests = SerializedArrays::recordList($value['recorded_manifests']);
        $instances = SerializedArrays::maps($value['instances']);
        $snapshots = SerializedArrays::snapshotGroups($value['snapshots']);
        $networkValue = $value['network'];
        $network = null;
        if ($networkValue !== null) {
            $network = StringKeyedMap::of(
                $networkValue,
                new \InvalidArgumentException('The legacy topology snapshot inventory is invalid.'),
            );
        }

        return new self(
            $scope,
            $promotedManifest,
            $recordedManifests,
            $instances,
            $snapshots,
            $network,
            $schema,
        );
    }

    /** @param array<array-key, mixed> $scope */
    private static function validateScope(array $scope, int $schema): void
    {
        $keys = $schema === 1
            ? ['remote', 'project', 'pool', 'topology_snapshot_namespace']
            : ['remote', 'project', 'pool'];
        if (
            ! in_array($schema, [1, 2], true)
            || array_keys($scope) !== $keys
            || ! array_all($scope, static fn (mixed $item): bool => is_string($item))
            || $schema === 1
            && $scope['topology_snapshot_namespace'] !== ''
        ) {
            throw new \InvalidArgumentException('The legacy topology snapshot inventory is invalid.');
        }
    }

    /** @param array<array-key, mixed> $value */
    private static function isMap(array $value): bool
    {
        return $value === [] || array_all(array_keys($value), static fn (int|string $key): bool => is_string($key));
    }
}
