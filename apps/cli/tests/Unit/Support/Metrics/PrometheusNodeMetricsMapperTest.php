<?php

declare(strict_types=1);

use App\Support\Metrics\PrometheusNodeMetricsMapper;

const PROMETHEUS_MAPPER_TEST_NOW = 1_700_100_000;

/** @param array<string, string> $labels */
function sample(array $labels, float|int|string $value, float $timestamp = 1_700_000_000.0): array
{
    return ['metric' => $labels, 'value' => [$timestamp, (string) $value]];
}

/** @param list<array{metric: array<string, string>, value: list<mixed>}> $samples */
function vector(array $samples): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $samples]];
}

function empty_vector(): array
{
    return vector([]);
}

describe(PrometheusNodeMetricsMapper::class, function (): void {
    it('maps a full snapshot for a 4-core Linux instance, matching the recorded SDK fixture', function (): void {
        $boot = PROMETHEUS_MAPPER_TEST_NOW - 1_053_784;
        $scalars = vector([
            sample(['__name__' => 'node_memory_MemTotal_bytes', 'instance' => '10.44.0.3:9100'], 8_589_934_592),
            sample(['__name__' => 'node_memory_MemAvailable_bytes', 'instance' => '10.44.0.3:9100'], 5_153_960_756),
            sample(['__name__' => 'node_memory_SwapTotal_bytes', 'instance' => '10.44.0.3:9100'], 2_147_483_648),
            sample(['__name__' => 'node_memory_SwapFree_bytes', 'instance' => '10.44.0.3:9100'], 2_147_483_648),
            sample(['__name__' => 'node_load1', 'instance' => '10.44.0.3:9100'], 0.52),
            sample(['__name__' => 'node_load5', 'instance' => '10.44.0.3:9100'], 0.61),
            sample(['__name__' => 'node_load15', 'instance' => '10.44.0.3:9100'], 0.58),
            sample(['__name__' => 'node_boot_time_seconds', 'instance' => '10.44.0.3:9100'], $boot),
        ]);
        $cores = vector([
            sample(['instance' => '10.44.0.3:9100', 'cpu' => '0'], 0.12),
            sample(['instance' => '10.44.0.3:9100', 'cpu' => '1'], 0.34),
            sample(['instance' => '10.44.0.3:9100', 'cpu' => '2'], 0.08),
            sample(['instance' => '10.44.0.3:9100', 'cpu' => '3'], 0.21),
        ]);
        $pressure = vector([
            sample(['__name__' => 'node_pressure_cpu_waiting_seconds_total', 'instance' => '10.44.0.3:9100'], 0.4),
            sample(['__name__' => 'node_pressure_memory_waiting_seconds_total', 'instance' => '10.44.0.3:9100'], 0.0),
            sample(['__name__' => 'node_pressure_io_waiting_seconds_total', 'instance' => '10.44.0.3:9100'], 1.1),
        ]);
        $disks = vector([
            sample(['__name__' => 'node_filesystem_size_bytes', 'instance' => '10.44.0.3:9100', 'mountpoint' => '/', 'fstype' => 'ext4'], 85_899_345_920),
            sample(['__name__' => 'node_filesystem_avail_bytes', 'instance' => '10.44.0.3:9100', 'mountpoint' => '/', 'fstype' => 'ext4'], 79_456_894_976),
        ]);

        $snapshots = PrometheusNodeMetricsMapper::map($scalars, $cores, $pressure, $disks, PROMETHEUS_MAPPER_TEST_NOW);

        expect($snapshots)->toHaveKey('10.44.0.3');
        expect($snapshots['10.44.0.3'])->toBe([
            'cores' => [0.12, 0.34, 0.08, 0.21],
            'memory' => ['used' => 3_435_973_836, 'total' => 8_589_934_592],
            'swap' => ['used' => 0, 'total' => 2_147_483_648],
            'load' => ['one' => 0.52, 'five' => 0.61, 'fifteen' => 0.58],
            'uptime_seconds' => 1_053_784,
            'pressure' => [
                'cpu' => ['some_avg10' => 0.4],
                'memory' => ['some_avg10' => 0.0],
                'io' => ['some_avg10' => 1.1],
            ],
            'disks' => [
                ['mount' => '/', 'used' => 6_442_450_944, 'total' => 85_899_345_920],
            ],
        ]);
    });

    it('orders a 16-core instance by CPU index, not by the order Prometheus returned them', function (): void {
        $scalars = vector([
            sample(['__name__' => 'node_memory_MemTotal_bytes', 'instance' => '10.44.0.7:9100'], 34_359_738_368),
            sample(['__name__' => 'node_memory_MemAvailable_bytes', 'instance' => '10.44.0.7:9100'], 17_179_869_184),
        ]);
        // Deliberately out of order and including a double-digit index, to prove ksort() by int, not by string.
        $order = [10, 2, 0, 15, 1, 11, 3, 12, 4, 13, 5, 14, 6, 9, 7, 8];
        $cores = vector(array_map(
            static fn (int $cpu): array => sample(['instance' => '10.44.0.7:9100', 'cpu' => (string) $cpu], $cpu / 100),
            $order,
        ));

        $snapshots = PrometheusNodeMetricsMapper::map($scalars, $cores, empty_vector(), empty_vector(), PROMETHEUS_MAPPER_TEST_NOW);

        expect($snapshots['10.44.0.7']['cores'])->toBe(array_map(
            static fn (int $cpu): float => $cpu / 100,
            range(0, 15),
        ));
    });

    it('zeroes pressure for an instance without /proc/pressure support', function (): void {
        $scalars = vector([
            sample(['__name__' => 'node_memory_MemTotal_bytes', 'instance' => '10.44.0.9:9100'], 4_294_967_296),
            sample(['__name__' => 'node_memory_MemAvailable_bytes', 'instance' => '10.44.0.9:9100'], 2_147_483_648),
        ]);

        $snapshots = PrometheusNodeMetricsMapper::map($scalars, empty_vector(), empty_vector(), empty_vector(), PROMETHEUS_MAPPER_TEST_NOW);

        expect($snapshots['10.44.0.9']['pressure'])->toBe([
            'cpu' => ['some_avg10' => 0.0],
            'memory' => ['some_avg10' => 0.0],
            'io' => ['some_avg10' => 0.0],
        ]);
    });

    it('reports zero swap when SwapTotal is present but zero', function (): void {
        $scalars = vector([
            sample(['__name__' => 'node_memory_MemTotal_bytes', 'instance' => '10.44.0.10:9100'], 4_294_967_296),
            sample(['__name__' => 'node_memory_MemAvailable_bytes', 'instance' => '10.44.0.10:9100'], 2_147_483_648),
            sample(['__name__' => 'node_memory_SwapTotal_bytes', 'instance' => '10.44.0.10:9100'], 0),
            sample(['__name__' => 'node_memory_SwapFree_bytes', 'instance' => '10.44.0.10:9100'], 0),
        ]);

        $snapshots = PrometheusNodeMetricsMapper::map($scalars, empty_vector(), empty_vector(), empty_vector(), PROMETHEUS_MAPPER_TEST_NOW);

        expect($snapshots['10.44.0.10']['swap'])->toBe(['used' => 0, 'total' => 0]);
    });

    it('keeps a mount path containing spaces intact and puts root first', function (): void {
        $scalars = vector([
            sample(['__name__' => 'node_memory_MemTotal_bytes', 'instance' => '10.44.0.11:9100'], 4_294_967_296),
            sample(['__name__' => 'node_memory_MemAvailable_bytes', 'instance' => '10.44.0.11:9100'], 2_147_483_648),
        ]);
        $disks = vector([
            sample(['__name__' => 'node_filesystem_size_bytes', 'instance' => '10.44.0.11:9100', 'mountpoint' => '/mnt/backup drive', 'fstype' => 'ext4'], 1_000_000_000),
            sample(['__name__' => 'node_filesystem_avail_bytes', 'instance' => '10.44.0.11:9100', 'mountpoint' => '/mnt/backup drive', 'fstype' => 'ext4'], 400_000_000),
            sample(['__name__' => 'node_filesystem_size_bytes', 'instance' => '10.44.0.11:9100', 'mountpoint' => '/', 'fstype' => 'ext4'], 85_899_345_920),
            sample(['__name__' => 'node_filesystem_avail_bytes', 'instance' => '10.44.0.11:9100', 'mountpoint' => '/', 'fstype' => 'ext4'], 79_456_894_976),
        ]);

        $snapshots = PrometheusNodeMetricsMapper::map($scalars, empty_vector(), empty_vector(), $disks, PROMETHEUS_MAPPER_TEST_NOW);

        expect($snapshots['10.44.0.11']['disks'])->toBe([
            ['mount' => '/', 'used' => 6_442_450_944, 'total' => 85_899_345_920],
            ['mount' => '/mnt/backup drive', 'used' => 600_000_000, 'total' => 1_000_000_000],
        ]);
    });

    it('excludes pseudo filesystems and a mount with no size', function (): void {
        $scalars = vector([
            sample(['__name__' => 'node_memory_MemTotal_bytes', 'instance' => '10.44.0.12:9100'], 4_294_967_296),
        ]);
        $disks = vector([
            sample(['__name__' => 'node_filesystem_size_bytes', 'instance' => '10.44.0.12:9100', 'mountpoint' => '/sys/firmware/efi/efivars', 'fstype' => 'efivarfs'], 65_536),
            sample(['__name__' => 'node_filesystem_size_bytes', 'instance' => '10.44.0.12:9100', 'mountpoint' => '/run', 'fstype' => 'tmpfs'], 1_048_576),
            sample(['__name__' => 'node_filesystem_size_bytes', 'instance' => '10.44.0.12:9100', 'mountpoint' => '/empty', 'fstype' => 'ext4'], 0),
            sample(['__name__' => 'node_filesystem_size_bytes', 'instance' => '10.44.0.12:9100', 'mountpoint' => '/data', 'fstype' => 'ext4'], 500_000_000),
            sample(['__name__' => 'node_filesystem_avail_bytes', 'instance' => '10.44.0.12:9100', 'mountpoint' => '/data', 'fstype' => 'ext4'], 250_000_000),
        ]);

        $snapshots = PrometheusNodeMetricsMapper::map($scalars, empty_vector(), empty_vector(), $disks, PROMETHEUS_MAPPER_TEST_NOW);

        expect($snapshots['10.44.0.12']['disks'])->toBe([
            ['mount' => '/data', 'used' => 250_000_000, 'total' => 500_000_000],
        ]);
    });

    it('clamps a busy ratio that did not advance between scrapes to zero, never dividing by zero', function (): void {
        $scalars = vector([
            sample(['__name__' => 'node_memory_MemTotal_bytes', 'instance' => '10.44.0.13:9100'], 4_294_967_296),
        ]);
        $cores = vector([
            // A rate() query never returns a negative number in practice, but a hostile or buggy
            // response should still clamp instead of producing a nonsensical ratio.
            sample(['instance' => '10.44.0.13:9100', 'cpu' => '0'], -0.0001),
            sample(['instance' => '10.44.0.13:9100', 'cpu' => '1'], 'NaN'),
            sample(['instance' => '10.44.0.13:9100', 'cpu' => '2'], '+Inf'),
        ]);

        $snapshots = PrometheusNodeMetricsMapper::map($scalars, $cores, empty_vector(), empty_vector(), PROMETHEUS_MAPPER_TEST_NOW);

        expect($snapshots['10.44.0.13']['cores'])->toBe([0.0, 0.0, 0.0]);
    });

    it('maps a Darwin instance from its own memory metric family, never from a stored platform field', function (): void {
        $scalars = vector([
            sample(['__name__' => 'node_memory_total_bytes', 'instance' => '10.6.0.5:9100'], 17_179_869_184),
            sample(['__name__' => 'node_memory_free_bytes', 'instance' => '10.6.0.5:9100'], 2_147_483_648),
            sample(['__name__' => 'node_memory_inactive_bytes', 'instance' => '10.6.0.5:9100'], 1_073_741_824),
            sample(['__name__' => 'node_memory_purgeable_bytes', 'instance' => '10.6.0.5:9100'], 536_870_912),
            sample(['__name__' => 'node_memory_active_bytes', 'instance' => '10.6.0.5:9100'], 8_589_934_592),
            sample(['__name__' => 'node_memory_wired_bytes', 'instance' => '10.6.0.5:9100'], 2_147_483_648),
            sample(['__name__' => 'node_memory_swap_total_bytes', 'instance' => '10.6.0.5:9100'], 1_073_741_824),
            sample(['__name__' => 'node_memory_swap_used_bytes', 'instance' => '10.6.0.5:9100'], 104_857_600),
            sample(['__name__' => 'node_load1', 'instance' => '10.6.0.5:9100'], 1.2),
            sample(['__name__' => 'node_load5', 'instance' => '10.6.0.5:9100'], 1.1),
            sample(['__name__' => 'node_load15', 'instance' => '10.6.0.5:9100'], 0.9),
            sample(['__name__' => 'node_boot_time_seconds', 'instance' => '10.6.0.5:9100'], PROMETHEUS_MAPPER_TEST_NOW - 86_400),
        ]);
        $cores = vector([
            sample(['instance' => '10.6.0.5:9100', 'cpu' => '0'], 0.05),
            sample(['instance' => '10.6.0.5:9100', 'cpu' => '1'], 0.1),
        ]);
        // Darwin's node_exporter has no PSI collector: no series for this instance at all.

        $snapshots = PrometheusNodeMetricsMapper::map($scalars, $cores, empty_vector(), empty_vector(), PROMETHEUS_MAPPER_TEST_NOW);

        expect($snapshots)->toHaveKey('10.6.0.5');
        expect($snapshots['10.6.0.5']['memory'])->toBe([
            'used' => 17_179_869_184 - (2_147_483_648 + 1_073_741_824 + 536_870_912),
            'total' => 17_179_869_184,
        ])
            ->and($snapshots['10.6.0.5']['swap'])->toBe(['used' => 104_857_600, 'total' => 1_073_741_824])
            ->and($snapshots['10.6.0.5']['uptime_seconds'])->toBe(86_400)
            ->and($snapshots['10.6.0.5']['pressure'])->toBe([
                'cpu' => ['some_avg10' => 0.0],
                'memory' => ['some_avg10' => 0.0],
                'io' => ['some_avg10' => 0.0],
            ]);
    });

    it('leaves an instance with no total-memory metric out of the map entirely, Linux or Darwin', function (): void {
        $scalars = vector([
            sample(['__name__' => 'node_load1', 'instance' => '10.44.0.14:9100'], 0.1),
        ]);

        $snapshots = PrometheusNodeMetricsMapper::map($scalars, empty_vector(), empty_vector(), empty_vector(), PROMETHEUS_MAPPER_TEST_NOW);

        expect($snapshots)->not->toHaveKey('10.44.0.14');
    });

    it('tolerates a malformed or error response from Prometheus without crashing', function (): void {
        $error = ['status' => 'error', 'errorType' => 'bad_data', 'error' => 'invalid query'];
        $notVector = ['status' => 'success', 'data' => ['resultType' => 'scalar', 'result' => [0, '1']]];
        $scalars = vector([
            sample(['__name__' => 'node_memory_MemTotal_bytes', 'instance' => '10.44.0.15:9100'], 4_294_967_296),
            // No "instance" label: unusable, must be skipped rather than crash the mapping.
            ['metric' => ['__name__' => 'node_load1'], 'value' => [1_700_000_000.0, '0.1']],
        ]);

        $snapshots = PrometheusNodeMetricsMapper::map($scalars, $error, $notVector, [], PROMETHEUS_MAPPER_TEST_NOW);

        expect($snapshots['10.44.0.15'])->toBe([
            'cores' => [],
            'memory' => ['used' => 0, 'total' => 4_294_967_296],
            'swap' => ['used' => 0, 'total' => 0],
            'load' => ['one' => 0.0, 'five' => 0.0, 'fifteen' => 0.0],
            'uptime_seconds' => 0,
            'pressure' => [
                'cpu' => ['some_avg10' => 0.0],
                'memory' => ['some_avg10' => 0.0],
                'io' => ['some_avg10' => 0.0],
            ],
            'disks' => [],
        ]);
    });
});
