<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\MetricsConfigurationRenderer;
use App\Infrastructure\Metrics\MetricsGeneratedFile;
use App\Infrastructure\Metrics\NodeResourcesDashboardRenderer;

it('renders the eight node resource panels in their fixed layout', function (): void {
    $dashboard = nodeResourcesDashboard(['app-dev']);

    expect(array_column($dashboard['panels'], 'title'))
        ->toBe([
            'Exporter Up',
            'CPU Used',
            'Memory Used',
            'Root Disk Used',
            'CPU By Mode',
            'Load Average',
            'Memory',
            'Network Throughput',
        ])
        ->and(array_column($dashboard['panels'], 'type'))
        ->toBe(['stat', 'stat', 'stat', 'stat', 'timeseries', 'timeseries', 'timeseries', 'timeseries'])
        ->and(array_column($dashboard['panels'], 'id'))
        ->toBe([1, 2, 3, 4, 5, 6, 7, 8])
        ->and(array_column($dashboard['panels'], 'gridPos'))
        ->toBe([
            ['h' => 4, 'w' => 4, 'x' => 0, 'y' => 0],
            ['h' => 4, 'w' => 5, 'x' => 4, 'y' => 0],
            ['h' => 4, 'w' => 5, 'x' => 9, 'y' => 0],
            ['h' => 4, 'w' => 5, 'x' => 14, 'y' => 0],
            ['h' => 8, 'w' => 12, 'x' => 0, 'y' => 4],
            ['h' => 8, 'w' => 12, 'x' => 12, 'y' => 4],
            ['h' => 8, 'w' => 12, 'x' => 0, 'y' => 12],
            ['h' => 8, 'w' => 12, 'x' => 12, 'y' => 12],
        ]);
});

it('pins the built-in dashboard PromQL contract', function (): void {
    $dashboard = nodeResourcesDashboard(['app-dev']);
    $expressions = array_merge(...array_map(
        static fn (array $panel): array => array_column($panel['targets'], 'expr'),
        $dashboard['panels'],
    ));

    expect($expressions)->toBe([
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
});

it('excludes container filesystems from the root disk query', function (): void {
    $dashboard = nodeResourcesDashboard(['app-dev']);
    $rootDisk = $dashboard['panels'][3];

    expect($rootDisk['title'])
        ->toBe('Root Disk Used')
        ->and(substr_count($rootDisk['targets'][0]['expr'], 'fstype!~"tmpfs|overlay|squashfs"'))
        ->toBe(2)
        ->and($rootDisk['targets'][0]['expr'])
        ->toContain('mountpoint="/"');
});

it('carries the units and thresholds each panel reads by', function (): void {
    $panels = nodeResourcesDashboard(['app-dev'])['panels'];
    $units = array_map(
        static fn (array $panel): string => $panel['fieldConfig']['defaults']['unit'],
        $panels,
    );

    expect($units)
        ->toBe(['short', 'percent', 'percent', 'percent', 'percent', 'short', 'decbytes', 'Bps'])
        ->and($panels[0]['fieldConfig']['defaults']['thresholds'])
        ->toBe([
            'mode' => 'absolute',
            'steps' => [
                ['color' => 'red', 'value' => null],
                ['color' => 'green', 'value' => 1],
            ],
        ])
        ->and(array_map(
            static fn (array $panel): array => array_column(
                $panel['fieldConfig']['defaults']['thresholds']['steps'],
                'value',
            ),
            [$panels[1], $panels[2], $panels[3]],
        ))
        ->toBe([[null, 70, 90], [null, 75, 90], [null, 80, 95]])
        ->and(array_map(
            static fn (array $panel): string => $panel['fieldConfig']['defaults']['thresholds']['mode'],
            [$panels[1], $panels[2], $panels[3]],
        ))
        ->toBe(['percentage', 'percentage', 'percentage'])
        ->and([$panels[1]['fieldConfig']['defaults']['min'], $panels[1]['fieldConfig']['defaults']['max']])
        ->toBe([0, 100]);
});

it('refreshes every thirty seconds over the last hour', function (): void {
    $dashboard = nodeResourcesDashboard(['app-dev']);

    expect($dashboard['refresh'])
        ->toBe('30s')
        ->and($dashboard['time'])
        ->toBe(['from' => 'now-1h', 'to' => 'now'])
        ->and($dashboard)
        ->toMatchArray([
            'uid' => 'orbit-node-resources',
            'title' => 'Orbit Node Resources',
            'schemaVersion' => 39,
        ]);
});

it('seeds the node variable with ordered options so panels resolve on first open', function (): void {
    $variable = nodeResourcesDashboard(['gateway', 'app-dev', 'app-dev', 'app-prod'])['templating']['list'][0];

    expect($variable['name'])
        ->toBe('node')
        ->and($variable['current'])
        ->toBe(['selected' => true, 'text' => 'app-dev', 'value' => 'app-dev'])
        ->and($variable['options'])
        ->toBe([
            ['selected' => true, 'text' => 'app-dev', 'value' => 'app-dev'],
            ['selected' => false, 'text' => 'app-prod', 'value' => 'app-prod'],
            ['selected' => false, 'text' => 'gateway', 'value' => 'gateway'],
        ])
        ->and($variable['query'])
        ->toBe('label_values(up{job="orbit-node-exporter"}, node)')
        ->and($variable['multi'])
        ->toBeFalse();
});

it('renders without options when no node is scraped yet', function (): void {
    $variable = nodeResourcesDashboard([])['templating']['list'][0];

    expect($variable['options'])
        ->toBe([])
        ->and($variable['current'])
        ->toBe(['selected' => true, 'text' => '', 'value' => '']);
});

it('provisions the dashboard for the scraped Prometheus targets', function (): void {
    $bundle = new MetricsConfigurationRenderer()->render(
        [['name' => 'app-dev', 'address' => '10.44.0.3'], ['name' => 'gateway', 'address' => '10.44.0.2']],
        'admin-password-sentinel',
    );

    $dashboards = array_values(array_filter(
        $bundle->files,
        static fn (MetricsGeneratedFile $file): bool => (
            $file->path === '/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json'
        ),
    ));
    $renderer = new NodeResourcesDashboardRenderer;

    expect($dashboards)
        ->toHaveCount(1)
        ->and($dashboards[0]->contents->sha256())
        ->toBe(hash('sha256', $renderer->render(['app-dev', 'gateway'])))
        ->not->toBe(hash('sha256', $renderer->render([])));
});

/**
 * @param  list<string>  $nodeNames
 * @return array{
 *     refresh: string,
 *     time: array{from: string, to: string},
 *     uid: string,
 *     title: string,
 *     schemaVersion: int,
 *     templating: array{list: list<array{
 *         name: string,
 *         multi: bool,
 *         query: string,
 *         current: array{selected: bool, text: string, value: string},
 *         options: list<array{selected: bool, text: string, value: string}>
 *     }>},
 *     panels: list<array{
 *         id: int,
 *         type: string,
 *         title: string,
 *         gridPos: array{h: int, w: int, x: int, y: int},
 *         fieldConfig: array{defaults: array<string, mixed>},
 *         targets: list<array{expr: string}>
 *     }>
 * }
 */
function nodeResourcesDashboard(array $nodeNames): array
{
    $content = new NodeResourcesDashboardRenderer()->render($nodeNames);

    expect($content)->toEndWith("\n");

    /**
     * @var array{
     *     refresh: string,
     *     time: array{from: string, to: string},
     *     uid: string,
     *     title: string,
     *     schemaVersion: int,
     *     templating: array{list: list<array{
     *         name: string,
     *         multi: bool,
     *         query: string,
     *         current: array{selected: bool, text: string, value: string},
     *         options: list<array{selected: bool, text: string, value: string}>
     *     }>},
     *     panels: list<array{
     *         id: int,
     *         type: string,
     *         title: string,
     *         gridPos: array{h: int, w: int, x: int, y: int},
     *         fieldConfig: array{defaults: array<string, mixed>},
     *         targets: list<array{expr: string}>
     *     }>
     * } $dashboard
     */
    $dashboard = json_decode($content, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    return $dashboard;
}
