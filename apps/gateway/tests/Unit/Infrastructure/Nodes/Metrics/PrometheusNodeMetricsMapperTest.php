<?php

declare(strict_types=1);

use App\Infrastructure\Nodes\Metrics\PrometheusNodeMetricsMapper;

const MAPPER_NOW = 1_700_000_000;

/**
 * @param  list<array{metric: array<string, string>, value: int|float}>  $samples
 * @return array<string, mixed>
 */
function prometheusVector(array $samples): array
{
    return [
        'status' => 'success',
        'data' => [
            'resultType' => 'vector',
            'result' => array_map(
                static fn (array $sample): array => [
                    'metric' => $sample['metric'],
                    'value' => [MAPPER_NOW, (string) $sample['value']],
                ],
                $samples,
            ),
        ],
    ];
}

/** @return array<string, mixed> */
function prometheusEmptyVector(): array
{
    return prometheusVector([]);
}

it('maps a 4-core Linux node with full metrics', function (): void {
    $scalars = prometheusVector([
        ['metric' => ['__name__' => 'node_memory_MemTotal_bytes', 'node' => 'app-dev'], 'value' => 8_589_934_592],
        ['metric' => ['__name__' => 'node_memory_MemAvailable_bytes', 'node' => 'app-dev'], 'value' => 5_153_960_756],
        ['metric' => ['__name__' => 'node_memory_SwapTotal_bytes', 'node' => 'app-dev'], 'value' => 2_147_483_648],
        ['metric' => ['__name__' => 'node_memory_SwapFree_bytes', 'node' => 'app-dev'], 'value' => 2_147_483_648],
        ['metric' => ['__name__' => 'node_load1', 'node' => 'app-dev'], 'value' => 0.52],
        ['metric' => ['__name__' => 'node_load5', 'node' => 'app-dev'], 'value' => 0.61],
        ['metric' => ['__name__' => 'node_load15', 'node' => 'app-dev'], 'value' => 0.58],
        ['metric' => ['__name__' => 'node_boot_time_seconds', 'node' => 'app-dev'], 'value' => MAPPER_NOW - 1_053_784],
    ]);
    $cores = prometheusVector([
        ['metric' => ['node' => 'app-dev', 'cpu' => '0'], 'value' => 0.12],
        ['metric' => ['node' => 'app-dev', 'cpu' => '1'], 'value' => 0.34],
        ['metric' => ['node' => 'app-dev', 'cpu' => '2'], 'value' => 0.08],
        ['metric' => ['node' => 'app-dev', 'cpu' => '3'], 'value' => 0.21],
    ]);
    $pressure = prometheusVector([
        ['metric' => ['__name__' => 'node_pressure_cpu_waiting_seconds_total', 'node' => 'app-dev'], 'value' => 0.4],
        ['metric' => ['__name__' => 'node_pressure_memory_waiting_seconds_total', 'node' => 'app-dev'], 'value' => 0.0],
        ['metric' => ['__name__' => 'node_pressure_io_waiting_seconds_total', 'node' => 'app-dev'], 'value' => 1.1],
    ]);
    $disks = prometheusVector([
        ['metric' => ['__name__' => 'node_filesystem_size_bytes', 'node' => 'app-dev', 'mountpoint' => '/'], 'value' => 85_899_345_920],
        ['metric' => ['__name__' => 'node_filesystem_avail_bytes', 'node' => 'app-dev', 'mountpoint' => '/'], 'value' => 79_456_894_976],
    ]);

    $snapshots = PrometheusNodeMetricsMapper::map($scalars, $cores, $pressure, $disks, MAPPER_NOW);

    expect($snapshots)->toHaveKey('app-dev');
    $node = $snapshots['app-dev'];

    expect($node['cores'])->toBe([0.12, 0.34, 0.08, 0.21])
        ->and($node['memory'])->toBe(['used' => 3_435_973_836, 'total' => 8_589_934_592])
        ->and($node['swap'])->toBe(['used' => 0, 'total' => 2_147_483_648])
        ->and($node['load'])->toBe(['one' => 0.52, 'five' => 0.61, 'fifteen' => 0.58])
        ->and($node['uptime_seconds'])->toBe(1_053_784)
        ->and($node['pressure'])->toBe([
            'cpu' => ['some_avg10' => 0.4],
            'memory' => ['some_avg10' => 0.0],
            'io' => ['some_avg10' => 1.1],
        ])
        ->and($node['disks'])->toBe([
            ['mount' => '/', 'used' => 6_442_450_944, 'total' => 85_899_345_920],
        ]);
});

it('orders a 16-core node numerically, not lexicographically', function (): void {
    $scalars = prometheusVector([
        ['metric' => ['__name__' => 'node_memory_MemTotal_bytes', 'node' => 'beast'], 'value' => 68_719_476_736],
        ['metric' => ['__name__' => 'node_memory_MemAvailable_bytes', 'node' => 'beast'], 'value' => 34_359_738_368],
    ]);
    $samples = [];

    for ($cpu = 0; $cpu < 16; $cpu++) {
        $samples[] = ['metric' => ['node' => 'beast', 'cpu' => (string) $cpu], 'value' => $cpu / 100];
    }

    $cores = prometheusVector($samples);

    $snapshots = PrometheusNodeMetricsMapper::map($scalars, $cores, prometheusEmptyVector(), prometheusEmptyVector(), MAPPER_NOW);

    expect($snapshots['beast']['cores'])->toBe(array_map(static fn (int $cpu): float => $cpu / 100, range(0, 15)));
});

it('reports zero swap when the exporter has no swap metrics', function (): void {
    $scalars = prometheusVector([
        ['metric' => ['__name__' => 'node_memory_MemTotal_bytes', 'node' => 'no-swap'], 'value' => 4_294_967_296],
        ['metric' => ['__name__' => 'node_memory_MemAvailable_bytes', 'node' => 'no-swap'], 'value' => 1_073_741_824],
    ]);

    $snapshots = PrometheusNodeMetricsMapper::map($scalars, prometheusEmptyVector(), prometheusEmptyVector(), prometheusEmptyVector(), MAPPER_NOW);

    expect($snapshots['no-swap']['swap'])->toBe(['used' => 0, 'total' => 0]);
});

it('zeroes pressure for a Node whose kernel has no /proc/pressure', function (): void {
    $scalars = prometheusVector([
        ['metric' => ['__name__' => 'node_memory_MemTotal_bytes', 'node' => 'old-kernel'], 'value' => 4_294_967_296],
        ['metric' => ['__name__' => 'node_memory_MemAvailable_bytes', 'node' => 'old-kernel'], 'value' => 1_073_741_824],
    ]);

    $snapshots = PrometheusNodeMetricsMapper::map($scalars, prometheusEmptyVector(), prometheusEmptyVector(), prometheusEmptyVector(), MAPPER_NOW);

    expect($snapshots['old-kernel']['pressure'])->toBe([
        'cpu' => ['some_avg10' => 0.0],
        'memory' => ['some_avg10' => 0.0],
        'io' => ['some_avg10' => 0.0],
    ]);
});

it('filters pseudo filesystems and zero-size entries, and puts / first', function (): void {
    $scalars = prometheusVector([
        ['metric' => ['__name__' => 'node_memory_MemTotal_bytes', 'node' => 'app-dev'], 'value' => 4_294_967_296],
        ['metric' => ['__name__' => 'node_memory_MemAvailable_bytes', 'node' => 'app-dev'], 'value' => 1_073_741_824],
    ]);
    $disks = prometheusVector([
        // Reported after /var in kernel mount order, so the mapper must still put / first.
        ['metric' => ['__name__' => 'node_filesystem_size_bytes', 'node' => 'app-dev', 'mountpoint' => '/var', 'fstype' => 'ext4'], 'value' => 10_737_418_240],
        ['metric' => ['__name__' => 'node_filesystem_avail_bytes', 'node' => 'app-dev', 'mountpoint' => '/var', 'fstype' => 'ext4'], 'value' => 5_368_709_120],
        ['metric' => ['__name__' => 'node_filesystem_size_bytes', 'node' => 'app-dev', 'mountpoint' => '/', 'fstype' => 'ext4'], 'value' => 85_899_345_920],
        ['metric' => ['__name__' => 'node_filesystem_avail_bytes', 'node' => 'app-dev', 'mountpoint' => '/', 'fstype' => 'ext4'], 'value' => 79_456_894_976],
        // Pseudo filesystems: excluded regardless of size.
        ['metric' => ['__name__' => 'node_filesystem_size_bytes', 'node' => 'app-dev', 'mountpoint' => '/run', 'fstype' => 'tmpfs'], 'value' => 838_860_800],
        ['metric' => ['__name__' => 'node_filesystem_avail_bytes', 'node' => 'app-dev', 'mountpoint' => '/run', 'fstype' => 'tmpfs'], 'value' => 838_860_800],
        ['metric' => ['__name__' => 'node_filesystem_size_bytes', 'node' => 'app-dev', 'mountpoint' => '/sys/firmware/efi/efivars', 'fstype' => 'efivarfs'], 'value' => 65_536],
        // Zero-size entry: excluded even though it is not a pseudo filesystem.
        ['metric' => ['__name__' => 'node_filesystem_size_bytes', 'node' => 'app-dev', 'mountpoint' => '/mnt/empty', 'fstype' => 'ext4'], 'value' => 0],
    ]);

    $snapshots = PrometheusNodeMetricsMapper::map($scalars, prometheusEmptyVector(), prometheusEmptyVector(), $disks, MAPPER_NOW);

    expect($snapshots['app-dev']['disks'])->toBe([
        ['mount' => '/', 'used' => 6_442_450_944, 'total' => 85_899_345_920],
        ['mount' => '/var', 'used' => 5_368_709_120, 'total' => 10_737_418_240],
    ]);
});

it('leaves out a Node Prometheus has never sampled', function (): void {
    $scalars = prometheusVector([
        ['metric' => ['__name__' => 'node_memory_MemTotal_bytes', 'node' => 'has-samples'], 'value' => 4_294_967_296],
        ['metric' => ['__name__' => 'node_memory_MemAvailable_bytes', 'node' => 'has-samples'], 'value' => 1_073_741_824],
    ]);

    $snapshots = PrometheusNodeMetricsMapper::map($scalars, prometheusEmptyVector(), prometheusEmptyVector(), prometheusEmptyVector(), MAPPER_NOW);

    expect($snapshots)->toHaveKey('has-samples')
        ->and($snapshots)->not->toHaveKey('never-scraped');
});

it('maps a Darwin node from its own memory metric family', function (): void {
    $scalars = prometheusVector([
        ['metric' => ['__name__' => 'node_memory_total_bytes', 'node' => 'mac-mini'], 'value' => 17_179_869_184],
        ['metric' => ['__name__' => 'node_memory_free_bytes', 'node' => 'mac-mini'], 'value' => 2_147_483_648],
        ['metric' => ['__name__' => 'node_memory_inactive_bytes', 'node' => 'mac-mini'], 'value' => 1_073_741_824],
        ['metric' => ['__name__' => 'node_memory_purgeable_bytes', 'node' => 'mac-mini'], 'value' => 536_870_912],
        ['metric' => ['__name__' => 'node_memory_swap_total_bytes', 'node' => 'mac-mini'], 'value' => 1_073_741_824],
        ['metric' => ['__name__' => 'node_memory_swap_used_bytes', 'node' => 'mac-mini'], 'value' => 104_857_600],
    ]);

    $snapshots = PrometheusNodeMetricsMapper::map($scalars, prometheusEmptyVector(), prometheusEmptyVector(), prometheusEmptyVector(), MAPPER_NOW);

    expect($snapshots['mac-mini']['memory'])->toBe(['used' => 13_421_772_800, 'total' => 17_179_869_184])
        ->and($snapshots['mac-mini']['swap'])->toBe(['used' => 104_857_600, 'total' => 1_073_741_824]);
});
