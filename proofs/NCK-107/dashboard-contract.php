<?php

/**
 * Asserts the dashboard Grafana serves carries the eight node resource panels
 * with their queries, units, thresholds and layout, plus the seeded node
 * variable. Reads the Grafana API response on stdin.
 *
 * Usage: dashboard-contract.php <comma separated scraped node names>
 */

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function equals(string $what, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        fail($what.' is '.json_encode($actual).', expected '.json_encode($expected));
    }
}

$expectedNodes = array_values(array_filter(explode(',', $argv[1] ?? '')));
$response = json_decode((string) stream_get_contents(STDIN), true);

if (! is_array($response) || ! isset($response['dashboard'])) {
    fail('Grafana did not serve the dashboard: '.substr(json_encode($response), 0, 400));
}

$dashboard = $response['dashboard'];
$panels = $dashboard['panels'] ?? [];

equals('the dashboard title', $dashboard['title'] ?? null, 'Orbit Node Resources');
equals('the dashboard uid', $dashboard['uid'] ?? null, 'orbit-node-resources');
equals('the refresh interval', $dashboard['refresh'] ?? null, '30s');
equals('the time window', $dashboard['time'] ?? null, ['from' => 'now-1h', 'to' => 'now']);

equals('the panel titles', array_column($panels, 'title'), [
    'Exporter Up',
    'CPU Used',
    'Memory Used',
    'Root Disk Used',
    'CPU By Mode',
    'Load Average',
    'Memory',
    'Network Throughput',
]);

equals('the panel types', array_column($panels, 'type'), [
    'stat', 'stat', 'stat', 'stat', 'timeseries', 'timeseries', 'timeseries', 'timeseries',
]);

equals('the panel layout', array_column($panels, 'gridPos'), [
    ['h' => 4, 'w' => 4, 'x' => 0, 'y' => 0],
    ['h' => 4, 'w' => 5, 'x' => 4, 'y' => 0],
    ['h' => 4, 'w' => 5, 'x' => 9, 'y' => 0],
    ['h' => 4, 'w' => 5, 'x' => 14, 'y' => 0],
    ['h' => 8, 'w' => 12, 'x' => 0, 'y' => 4],
    ['h' => 8, 'w' => 12, 'x' => 12, 'y' => 4],
    ['h' => 8, 'w' => 12, 'x' => 0, 'y' => 12],
    ['h' => 8, 'w' => 12, 'x' => 12, 'y' => 12],
]);

$defaults = array_map(static fn (array $panel): array => $panel['fieldConfig']['defaults'], $panels);

equals('the panel units', array_column($defaults, 'unit'), [
    'short', 'percent', 'percent', 'percent', 'percent', 'short', 'decbytes', 'Bps',
]);

equals('the Exporter Up thresholds', $defaults[0]['thresholds'], [
    'mode' => 'absolute',
    'steps' => [
        ['color' => 'red', 'value' => null],
        ['color' => 'green', 'value' => 1],
    ],
]);

foreach ([1 => [70, 90], 2 => [75, 90], 3 => [80, 95]] as $index => [$warning, $critical]) {
    equals($panels[$index]['title'].' thresholds', $defaults[$index]['thresholds'], [
        'mode' => 'percentage',
        'steps' => [
            ['color' => 'green', 'value' => null],
            ['color' => 'orange', 'value' => $warning],
            ['color' => 'red', 'value' => $critical],
        ],
    ]);
    equals($panels[$index]['title'].' bounds', [$defaults[$index]['min'], $defaults[$index]['max']], [0, 100]);
}

$expressions = array_merge(...array_map(
    static fn (array $panel): array => array_column($panel['targets'], 'expr'),
    $panels,
));

equals('the panel queries', $expressions, [
    'up{job="orbit-node-exporter",node=~"$node"}',
    '100 - (avg by (node) (rate(node_cpu_seconds_total{job="orbit-node-exporter",mode="idle",node=~"$node"}[5m])) * 100)',
    '100 * (1 - (node_memory_MemAvailable_bytes{job="orbit-node-exporter",node=~"$node"} / node_memory_MemTotal_bytes{job="orbit-node-exporter",node=~"$node"}))',
    '100 * (1 - (node_filesystem_avail_bytes{job="orbit-node-exporter",node=~"$node",mountpoint="/",fstype!~"tmpfs|overlay|squashfs"} / node_filesystem_size_bytes{job="orbit-node-exporter",node=~"$node",mountpoint="/",fstype!~"tmpfs|overlay|squashfs"}))',
    '100 - (avg by (mode) (rate(node_cpu_seconds_total{job="orbit-node-exporter",node=~"$node",mode!="idle"}[5m])) * 100)',
    'node_load1{job="orbit-node-exporter",node=~"$node"}',
    'node_load5{job="orbit-node-exporter",node=~"$node"}',
    'node_load15{job="orbit-node-exporter",node=~"$node"}',
    'node_memory_MemTotal_bytes{job="orbit-node-exporter",node=~"$node"} - node_memory_MemAvailable_bytes{job="orbit-node-exporter",node=~"$node"}',
    'node_memory_MemAvailable_bytes{job="orbit-node-exporter",node=~"$node"}',
    'sum by (node) (rate(node_network_receive_bytes_total{job="orbit-node-exporter",node=~"$node",device!~"lo|docker.*|br-.*|veth.*"}[5m]))',
    'sum by (node) (rate(node_network_transmit_bytes_total{job="orbit-node-exporter",node=~"$node",device!~"lo|docker.*|br-.*|veth.*"}[5m]))',
]);

$variable = $dashboard['templating']['list'][0] ?? [];

equals('the template variable', $variable['name'] ?? null, 'node');
equals('the variable query', $variable['query'] ?? null, 'label_values(up{job="orbit-node-exporter"}, node)');
equals('the seeded variable options', array_column($variable['options'] ?? [], 'value'), $expectedNodes);
equals('the seeded variable selection', $variable['current']['value'] ?? null, $expectedNodes[0] ?? '');

if (! in_array($variable['current']['value'] ?? null, $expectedNodes, true)) {
    fail('the seeded variable selects a node Prometheus does not scrape');
}

echo 'dashboard: 8 panels with pinned queries, units, thresholds and layout; refresh 30s over now-1h; ',
    'node variable seeded with ', implode(',', $expectedNodes), ' (current ', $variable['current']['value'], ")\n";
