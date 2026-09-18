<?php

declare(strict_types=1);

namespace App\Support\Metrics;

/**
 * Maps decoded Prometheus instant-query API responses (as Grafana's datasource proxy replays
 * them) into the raw metrics snapshot shape `NodeMetricsData::fromRaw()` consumes, keyed by the
 * scraped `instance` label (`WireGuardIP:9100`, node_exporter's own port; see
 * `MetricsFootprint::ExporterPort`) with the port stripped, so the caller can match it against a
 * Node's `wireguard_ip` without trusting any custom label.
 *
 * Pure and side-effect free: every value comes from the four decoded response arrays passed in,
 * so it is testable from recorded Prometheus JSON without a Gateway, an HTTP client, or a clock.
 * An instance's memory family (Linux's `node_memory_MemTotal_bytes`/`MemAvailable_bytes` versus
 * Darwin's `node_memory_total_bytes` and friends) is detected per instance from which metrics its
 * exporter actually reported, never from a stored `platform` field, which can be stale or wrong.
 * An instance without either total-memory metric is left out of the map entirely: the caller
 * treats that as "no samples yet", not a zeroed snapshot.
 */
final readonly class PrometheusNodeMetricsMapper
{
    /** @var non-empty-list<string> Pseudo and virtual filesystems excluded from `disks`. */
    private const array PSEUDO_FILESYSTEM_TYPES = [
        'tmpfs', 'devtmpfs', 'overlay', 'squashfs', 'efivarfs', 'proc', 'sysfs', 'cgroup', 'cgroup2', 'ramfs',
    ];

    public const string LINUX_MEMORY_TOTAL_METRIC = 'node_memory_MemTotal_bytes';

    public const string DARWIN_MEMORY_TOTAL_METRIC = 'node_memory_total_bytes';

    /**
     * @param  array<string, mixed>  $scalars  Decoded `/api/v1/query` response for memory, swap,
     *                                         load, and boot time (one value per series).
     * @param  array<string, mixed>  $cores  Decoded response for per-core busy ratios.
     * @param  array<string, mixed>  $pressure  Decoded response for PSI "some" pressure rates.
     * @param  array<string, mixed>  $disks  Decoded response for filesystem size and available bytes.
     * @param  int  $now  Unix timestamp used to turn `node_boot_time_seconds` into an uptime.
     * @return array<string, array<string, mixed>> Raw snapshot arrays keyed by instance IPv4 address.
     */
    public static function map(array $scalars, array $cores, array $pressure, array $disks, int $now): array
    {
        $scalarsByInstance = self::groupScalars($scalars);
        $coresByInstance = self::groupCores($cores);
        $pressureByInstance = self::groupPressure($pressure);
        $disksByInstance = self::groupDisks($disks);

        $snapshots = [];

        foreach ($scalarsByInstance as $instance => $values) {
            $memory = self::memory($values);

            if ($memory === null) {
                continue;
            }

            $snapshots[$instance] = [
                'cores' => $coresByInstance[$instance] ?? [],
                'memory' => $memory,
                'swap' => self::swap($values),
                'load' => [
                    'one' => self::float($values['node_load1'] ?? null),
                    'five' => self::float($values['node_load5'] ?? null),
                    'fifteen' => self::float($values['node_load15'] ?? null),
                ],
                'uptime_seconds' => self::uptime($values, $now),
                'pressure' => $pressureByInstance[$instance] ?? self::zeroPressure(),
                'disks' => $disksByInstance[$instance] ?? [],
            ];
        }

        return $snapshots;
    }

    /**
     * @param  array<string, float>  $values
     * @return array{used: int, total: int}|null Null when neither memory family is present.
     */
    private static function memory(array $values): ?array
    {
        if (array_key_exists(self::LINUX_MEMORY_TOTAL_METRIC, $values)) {
            $total = $values[self::LINUX_MEMORY_TOTAL_METRIC];
            $available = $values['node_memory_MemAvailable_bytes'] ?? $total;

            return ['used' => self::clampInt($total - $available), 'total' => self::clampInt($total)];
        }

        if (array_key_exists(self::DARWIN_MEMORY_TOTAL_METRIC, $values)) {
            $total = $values[self::DARWIN_MEMORY_TOTAL_METRIC];
            $free = $values['node_memory_free_bytes'] ?? 0.0;
            $inactive = $values['node_memory_inactive_bytes'] ?? 0.0;
            $purgeable = $values['node_memory_purgeable_bytes'] ?? 0.0;

            return ['used' => self::clampInt($total - ($free + $inactive + $purgeable)), 'total' => self::clampInt($total)];
        }

        return null;
    }

    /**
     * @param  array<string, float>  $values
     * @return array{used: int, total: int}
     */
    private static function swap(array $values): array
    {
        if (array_key_exists('node_memory_SwapTotal_bytes', $values)) {
            $total = $values['node_memory_SwapTotal_bytes'];
            $free = $values['node_memory_SwapFree_bytes'] ?? 0.0;

            return ['used' => self::clampInt($total - $free), 'total' => self::clampInt($total)];
        }

        if (array_key_exists('node_memory_swap_total_bytes', $values)) {
            $total = $values['node_memory_swap_total_bytes'];
            $used = $values['node_memory_swap_used_bytes'] ?? 0.0;

            return ['used' => self::clampInt(min($used, $total)), 'total' => self::clampInt($total)];
        }

        return ['used' => 0, 'total' => 0];
    }

    /** @param  array<string, float>  $values */
    private static function uptime(array $values, int $now): int
    {
        $boot = $values['node_boot_time_seconds'] ?? null;

        if ($boot === null) {
            return 0;
        }

        return max(0, (int) round($now - $boot));
    }

    /** @return array{cpu: array{some_avg10: float}, memory: array{some_avg10: float}, io: array{some_avg10: float}} */
    private static function zeroPressure(): array
    {
        return [
            'cpu' => ['some_avg10' => 0.0],
            'memory' => ['some_avg10' => 0.0],
            'io' => ['some_avg10' => 0.0],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array<string, float>> Instance address to metric name to value.
     */
    private static function groupScalars(array $response): array
    {
        $grouped = [];

        foreach (self::vector($response) as $sample) {
            $instance = self::instanceAddress($sample);
            $name = self::label($sample, '__name__');

            if ($instance === null || $name === null) {
                continue;
            }

            $grouped[$instance][$name] = self::sampleValue($sample);
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, list<float>> Instance address to per-core busy ratios, ordered by core index.
     */
    private static function groupCores(array $response): array
    {
        $byInstance = [];

        foreach (self::vector($response) as $sample) {
            $instance = self::instanceAddress($sample);
            $cpu = self::label($sample, 'cpu');

            if ($instance === null || $cpu === null || ! is_numeric($cpu)) {
                continue;
            }

            $byInstance[$instance][(int) $cpu] = max(0.0, min(1.0, self::sampleValue($sample)));
        }

        $grouped = [];

        foreach ($byInstance as $instance => $cores) {
            ksort($cores);
            $grouped[$instance] = array_values($cores);
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array{cpu: array{some_avg10: float}, memory: array{some_avg10: float}, io: array{some_avg10: float}}>
     */
    private static function groupPressure(array $response): array
    {
        $grouped = [];

        foreach (self::vector($response) as $sample) {
            $instance = self::instanceAddress($sample);
            $name = self::label($sample, '__name__');

            if ($instance === null || $name === null) {
                continue;
            }

            $kind = match (true) {
                str_starts_with($name, 'node_pressure_cpu_') => 'cpu',
                str_starts_with($name, 'node_pressure_memory_') => 'memory',
                str_starts_with($name, 'node_pressure_io_') => 'io',
                default => null,
            };

            if ($kind === null) {
                continue;
            }

            $grouped[$instance] ??= self::zeroPressure();
            $grouped[$instance][$kind] = ['some_avg10' => max(0.0, self::sampleValue($sample))];
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, list<array{mount: string, used: int, total: int}>>
     */
    private static function groupDisks(array $response): array
    {
        /** @var array<string, array<string, array{size?: float, avail?: float}>> $raw */
        $raw = [];

        foreach (self::vector($response) as $sample) {
            $instance = self::instanceAddress($sample);
            $name = self::label($sample, '__name__');
            $mount = self::label($sample, 'mountpoint');
            $fstype = self::label($sample, 'fstype');

            if ($instance === null || $name === null || $mount === null) {
                continue;
            }

            if ($fstype !== null && in_array($fstype, self::PSEUDO_FILESYSTEM_TYPES, true)) {
                continue;
            }

            $key = $name === 'node_filesystem_size_bytes' ? 'size' : ($name === 'node_filesystem_avail_bytes' ? 'avail' : null);

            if ($key === null) {
                continue;
            }

            $raw[$instance][$mount][$key] = self::sampleValue($sample);
        }

        $grouped = [];

        foreach ($raw as $instance => $mounts) {
            $entries = [];

            foreach ($mounts as $mount => $sizes) {
                $total = $sizes['size'] ?? null;

                if ($total === null || $total <= 0.0) {
                    continue;
                }

                $avail = $sizes['avail'] ?? $total;
                $entries[] = ['mount' => $mount, 'used' => self::clampInt($total - $avail), 'total' => self::clampInt($total)];
            }

            usort($entries, static function (array $a, array $b): int {
                if ($a['mount'] === '/') {
                    return -1;
                }

                if ($b['mount'] === '/') {
                    return 1;
                }

                return $a['mount'] <=> $b['mount'];
            });

            $grouped[$instance] = $entries;
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array{metric: array<string, string>, value: list<mixed>}>
     */
    private static function vector(array $response): array
    {
        if (($response['status'] ?? null) !== 'success') {
            return [];
        }

        $data = $response['data'] ?? null;

        if (! is_array($data) || ($data['resultType'] ?? null) !== 'vector') {
            return [];
        }

        $result = $data['result'] ?? null;

        return is_array($result) ? $result : [];
    }

    /**
     * The scraped `instance` label with its port stripped, e.g. "10.44.0.5:9100" -> "10.44.0.5".
     *
     * @param  array<string, mixed>  $sample
     */
    private static function instanceAddress(array $sample): ?string
    {
        $instance = self::label($sample, 'instance');

        if ($instance === null) {
            return null;
        }

        $address = strrpos($instance, ':') !== false ? substr($instance, 0, strrpos($instance, ':')) : $instance;

        return $address === '' ? null : $address;
    }

    /** @param  array{metric?: mixed}  $sample */
    private static function label(array $sample, string $name): ?string
    {
        $metric = $sample['metric'] ?? null;

        if (! is_array($metric) || ! is_string($metric[$name] ?? null)) {
            return null;
        }

        return $metric[$name];
    }

    /** @param  array{value?: mixed}  $sample */
    private static function sampleValue(array $sample): float
    {
        $value = $sample['value'] ?? null;

        if (! is_array($value) || ! array_key_exists(1, $value)) {
            return 0.0;
        }

        return self::float($value[1]);
    }

    private static function float(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        $float = (float) $value;

        return is_finite($float) ? $float : 0.0;
    }

    private static function clampInt(float $value): int
    {
        return (int) round(max(0.0, $value));
    }
}
