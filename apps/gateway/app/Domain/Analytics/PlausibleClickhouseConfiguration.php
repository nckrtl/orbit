<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Shared\ResourceOperationException;
use RuntimeException;

/**
 * Plausible's own ClickHouse configuration, copied unchanged from Plausible Community Edition into
 * `resources/analytics/clickhouse`. The analytics role publishes each file under one Orbit-owned
 * host directory and mounts it read-only where Plausible's compose file mounts it (ADR 0142).
 */
final readonly class PlausibleClickhouseConfiguration
{
    public const string HOST_DIRECTORY = '/etc/orbit/analytics/clickhouse';

    /** @var array<string, string> Each file relative to the resource and host directories, with its container path. */
    public const array FILES = [
        'config.d/logs.xml' => '/etc/clickhouse-server/config.d/logs.xml',
        'config.d/ipv4-only.xml' => '/etc/clickhouse-server/config.d/ipv4-only.xml',
        'config.d/low-resources.xml' => '/etc/clickhouse-server/config.d/low-resources.xml',
        'users.d/default-profile-low-resources-overrides.xml' => '/etc/clickhouse-server/users.d/default-profile-low-resources-overrides.xml',
    ];

    /** @return non-empty-list<string> */
    public static function hostDirectories(): array
    {
        return [self::HOST_DIRECTORY.'/config.d', self::HOST_DIRECTORY.'/users.d'];
    }

    public static function hostPath(string $file): string
    {
        return self::HOST_DIRECTORY.'/'.$file;
    }

    public static function contents(string $file): string
    {
        $contents = file_get_contents(resource_path("analytics/clickhouse/{$file}"));

        if ($contents === false) {
            throw new RuntimeException("The Plausible ClickHouse file [{$file}] is missing from the Gateway.");
        }

        return $contents;
    }

    /** @return list<array{source: string, target: string, read_only: true}> */
    public static function mounts(): array
    {
        $mounts = [];

        foreach (self::FILES as $file => $target) {
            $mounts[] = ['source' => self::hostPath($file), 'target' => $target, 'read_only' => true];
        }

        return $mounts;
    }

    /**
     * The mounts a Process's volumes still lack. Another volume on one of the container paths is
     * the operator's, so the role refuses instead of replacing it.
     *
     * @return list<array{source: string, target: string, read_only: true}>
     */
    public static function missingMounts(mixed $volumes, string $processName): array
    {
        $volumes = is_array($volumes) ? $volumes : [];
        $missing = [];

        foreach (self::mounts() as $mount) {
            $present = false;

            foreach ($volumes as $volume) {
                if (! is_array($volume) || ($volume['target'] ?? null) !== $mount['target']) {
                    continue;
                }

                if (($volume['source'] ?? null) !== $mount['source'] || ($volume['read_only'] ?? false) !== true) {
                    throw new ResourceOperationException(
                        errorCode: 'analytics.clickhouse_mount_conflict',
                        message: "Process [{$processName}] already mounts another volume at [{$mount['target']}].",
                        status: 409,
                    );
                }

                $present = true;
            }

            if (! $present) {
                $missing[] = $mount;
            }
        }

        return $missing;
    }
}
