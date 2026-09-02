<?php

declare(strict_types=1);

namespace App\E2E\Value;

/** @mago-expect lint:cyclomatic-complexity The retained record validates every nested inventory section. */
final readonly class LegacyTopologySnapshotInventory
{
    /**
     * @param array{remote:string,project:string,pool:string,topology_snapshot_namespace:string} $scope
     * @param array<string, mixed> $promotedManifest
     * @param list<array<string, mixed>> $recordedManifests
     * @param array<string, array<string, mixed>> $instances
     * @param array<string, list<array{name:string,created_at:string}>> $snapshots
     * @param array<string, mixed>|null $network
     * @mago-expect lint:excessive-parameter-list The inventory sections form one canonical authorization record.
     */
    public function __construct(
        public array $scope,
        public array $promotedManifest,
        public array $recordedManifests,
        public array $instances,
        public array $snapshots,
        public ?array $network,
    ) {}

    /** @return list<string> */
    public function resourceNames(): array
    {
        $names = array_keys($this->instances);
        if ($this->network !== null) {
            $names[] = (string) $this->network['name'];
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => 1,
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
            || ($value['schema'] ?? null) !== 1
            || ! is_array($value['scope'])
            || array_keys($value['scope']) !== ['remote', 'project', 'pool', 'topology_snapshot_namespace']
            || ! array_all($value['scope'], static fn (mixed $item): bool => is_string($item))
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

        /** @var array{remote:string,project:string,pool:string,topology_snapshot_namespace:string} $scope */
        $scope = $value['scope'];
        /** @var array<string, mixed> $promotedManifest */
        $promotedManifest = $value['promoted_manifest'];
        /** @var list<array<string, mixed>> $recordedManifests */
        $recordedManifests = $value['recorded_manifests'];
        /** @var array<string, array<string, mixed>> $instances */
        $instances = $value['instances'];
        /** @var array<string, list<array{name:string,created_at:string}>> $snapshots */
        $snapshots = $value['snapshots'];
        /** @var array<string, mixed>|null $network */
        $network = $value['network'];

        return new self($scope, $promotedManifest, $recordedManifests, $instances, $snapshots, $network);
    }

    /** @param array<array-key, mixed> $value */
    private static function isMap(array $value): bool
    {
        return $value === [] || array_all(array_keys($value), static fn (int|string $key): bool => is_string($key));
    }
}
