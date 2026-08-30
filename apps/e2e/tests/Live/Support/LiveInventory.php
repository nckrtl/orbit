<?php

declare(strict_types=1);

namespace Tests\Live\Support;

use InvalidArgumentException;

/**
 * Reduce a raw Incus inventory to the fields that identify a resource, so a
 * before-and-after comparison ignores runtime noise (memory usage, volatile
 * keys, `used_by` references) and reports exactly which resource changed.
 *
 * @mago-expect lint:cyclomatic-complexity Every projected field is read defensively from raw Incus JSON.
 */
final class LiveInventory
{
    /**
     * @param list<array<array-key, mixed>> $instances
     * @param list<array<array-key, mixed>> $networks
     * @return array{instances: array<string, array<string, mixed>>, networks: array<string, array<string, mixed>>}
     */
    public static function fingerprint(array $instances, array $networks): array
    {
        $instanceIdentity = [];
        foreach ($instances as $instance) {
            $instanceIdentity[self::name($instance)] = [
                'type' => $instance['type'] ?? null,
                'status' => $instance['status'] ?? null,
                'project' => $instance['project'] ?? null,
                'config' => self::stableConfig($instance['config'] ?? []),
                'devices' => $instance['expanded_devices'] ?? $instance['devices'] ?? [],
            ];
        }
        $networkIdentity = [];
        foreach ($networks as $network) {
            $networkIdentity[self::name($network)] = [
                'type' => $network['type'] ?? null,
                'managed' => $network['managed'] ?? null,
                'config' => self::stableConfig($network['config'] ?? []),
            ];
        }
        ksort($instanceIdentity, SORT_STRING);
        ksort($networkIdentity, SORT_STRING);

        return ['instances' => $instanceIdentity, 'networks' => $networkIdentity];
    }

    /**
     * The exact names, out of one list, that the inventory still holds.
     *
     * @param list<array<array-key, mixed>> $instances
     * @param list<array<array-key, mixed>> $networks
     * @param list<string> $names
     * @return list<string>
     */
    public static function observedNames(array $instances, array $networks, array $names): array
    {
        $observed = [];
        foreach ([...$instances, ...$networks] as $resource) {
            $observed[self::name($resource)] = true;
        }

        return array_values(array_filter($names, static fn (string $name): bool => isset($observed[$name])));
    }

    /** @param array<array-key, mixed> $resource */
    private static function name(array $resource): string
    {
        $name = $resource['name'] ?? null;
        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('An Incus resource must carry a string name.');
        }

        return $name;
    }

    /** @return array<string, mixed> */
    private static function stableConfig(mixed $config): array
    {
        if (! is_array($config)) {
            return [];
        }
        $stable = [];
        /** @mago-expect analysis:mixed-assignment Incus config values are opaque strings kept verbatim. */
        foreach ($config as $key => $value) {
            if (is_string($key) && ! str_starts_with($key, 'volatile.')) {
                $stable[$key] = $value;
            }
        }
        ksort($stable, SORT_STRING);

        return $stable;
    }
}
