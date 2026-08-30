<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use JsonException;
use RuntimeException;

final readonly class NodeResourcesDashboardRenderer
{
    private const string Datasource = 'orbit-prometheus';

    /**
     * Renders the provisioned Grafana dashboard for the scraped node fleet.
     *
     * @param  list<string>  $nodeNames  every node label Prometheus scrapes
     */
    public function render(array $nodeNames = []): string
    {
        $names = array_values(array_unique($nodeNames));
        sort($names);
        $selected = $names[0] ?? '';

        try {
            $content = json_encode(
                $this->dashboard($selected, $names),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The Orbit node resources Grafana dashboard could not be encoded.',
                previous: $exception,
            );
        }

        return "{$content}\n";
    }

    /**
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    private function dashboard(string $selected, array $names): array
    {
        return [
            'annotations' => [
                'list' => [
                    [
                        'builtIn' => 1,
                        'datasource' => ['type' => 'grafana', 'uid' => '-- Grafana --'],
                        'enable' => true,
                        'hide' => true,
                        'iconColor' => 'rgba(0, 211, 255, 1)',
                        'name' => 'Annotations & Alerts',
                        'type' => 'dashboard',
                    ],
                ],
            ],
            'editable' => true,
            'fiscalYearStartMonth' => 0,
            'graphTooltip' => 0,
            'id' => null,
            'links' => [],
            'liveNow' => false,
            'panels' => $this->panels(),
            'refresh' => '30s',
            'schemaVersion' => 39,
            'style' => 'dark',
            'tags' => ['orbit', 'node-exporter'],
            'templating' => ['list' => [$this->nodeVariable($selected, $names)]],
            'time' => ['from' => 'now-1h', 'to' => 'now'],
            'timepicker' => [],
            'timezone' => 'browser',
            'title' => 'Orbit Node Resources',
            'uid' => 'orbit-node-resources',
            'version' => 1,
            'weekStart' => '',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function panels(): array
    {
        return [
            $this->exporterUpPanel(),
            $this->percentStatPanel(
                id: 2,
                title: 'CPU Used',
                column: 4,
                thresholds: [70, 90],
                expr: '100 - (avg by (node) (rate(node_cpu_seconds_total{job="orbit-node-exporter",mode="idle",node=~"$node"}[5m])) * 100)',
            ),
            $this->percentStatPanel(
                id: 3,
                title: 'Memory Used',
                column: 9,
                thresholds: [75, 90],
                expr: '100 * (1 - (node_memory_MemAvailable_bytes{job="orbit-node-exporter",node=~"$node"} / node_memory_MemTotal_bytes{job="orbit-node-exporter",node=~"$node"}))',
            ),
            $this->percentStatPanel(
                id: 4,
                title: 'Root Disk Used',
                column: 14,
                thresholds: [80, 95],
                // tmpfs, overlay and squashfs mounts report a phantom root filesystem on any node running Docker.
                expr: '100 * (1 - (node_filesystem_avail_bytes{job="orbit-node-exporter",node=~"$node",mountpoint="/",fstype!~"tmpfs|overlay|squashfs"} / node_filesystem_size_bytes{job="orbit-node-exporter",node=~"$node",mountpoint="/",fstype!~"tmpfs|overlay|squashfs"}))',
            ),
            $this->timeseriesPanel(
                id: 5,
                title: 'CPU By Mode',
                gridPos: ['h' => 8, 'w' => 12, 'x' => 0, 'y' => 4],
                unit: 'percent',
                targets: [
                    $this->target(
                        'A',
                        '100 - (avg by (mode) (rate(node_cpu_seconds_total{job="orbit-node-exporter",node=~"$node",mode!="idle"}[5m])) * 100)',
                        '{{mode}}',
                    ),
                ],
            ),
            $this->timeseriesPanel(
                id: 6,
                title: 'Load Average',
                gridPos: ['h' => 8, 'w' => 12, 'x' => 12, 'y' => 4],
                unit: 'short',
                targets: [
                    $this->target('A', 'node_load1{job="orbit-node-exporter",node=~"$node"}', 'load1'),
                    $this->target('B', 'node_load5{job="orbit-node-exporter",node=~"$node"}', 'load5'),
                    $this->target('C', 'node_load15{job="orbit-node-exporter",node=~"$node"}', 'load15'),
                ],
            ),
            $this->timeseriesPanel(
                id: 7,
                title: 'Memory',
                gridPos: ['h' => 8, 'w' => 12, 'x' => 0, 'y' => 12],
                unit: 'decbytes',
                targets: [
                    $this->target(
                        'A',
                        'node_memory_MemTotal_bytes{job="orbit-node-exporter",node=~"$node"} - node_memory_MemAvailable_bytes{job="orbit-node-exporter",node=~"$node"}',
                        'used',
                    ),
                    $this->target(
                        'B',
                        'node_memory_MemAvailable_bytes{job="orbit-node-exporter",node=~"$node"}',
                        'available',
                    ),
                ],
            ),
            $this->timeseriesPanel(
                id: 8,
                title: 'Network Throughput',
                gridPos: ['h' => 8, 'w' => 12, 'x' => 12, 'y' => 12],
                unit: 'Bps',
                targets: [
                    $this->target(
                        'A',
                        'sum by (node) (rate(node_network_receive_bytes_total{job="orbit-node-exporter",node=~"$node",device!~"lo|docker.*|br-.*|veth.*"}[5m]))',
                        'receive',
                    ),
                    $this->target(
                        'B',
                        'sum by (node) (rate(node_network_transmit_bytes_total{job="orbit-node-exporter",node=~"$node",device!~"lo|docker.*|br-.*|veth.*"}[5m]))',
                        'transmit',
                    ),
                ],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exporterUpPanel(): array
    {
        return [
            'datasource' => $this->prometheus(),
            'fieldConfig' => [
                'defaults' => [
                    'mappings' => [],
                    'thresholds' => [
                        'mode' => 'absolute',
                        'steps' => [
                            ['color' => 'red', 'value' => null],
                            ['color' => 'green', 'value' => 1],
                        ],
                    ],
                    'unit' => 'short',
                ],
                'overrides' => [],
            ],
            'gridPos' => ['h' => 4, 'w' => 4, 'x' => 0, 'y' => 0],
            'id' => 1,
            'options' => $this->statOptions(),
            'targets' => [$this->target('A', 'up{job="orbit-node-exporter",node=~"$node"}')],
            'title' => 'Exporter Up',
            'type' => 'stat',
        ];
    }

    /**
     * The percentage stat panels share one row, so only their column varies.
     *
     * @param  array{int, int}  $thresholds  the warning and critical percentages
     * @return array<string, mixed>
     */
    private function percentStatPanel(int $id, string $title, int $column, array $thresholds, string $expr): array
    {
        [$warning, $critical] = $thresholds;

        return [
            'datasource' => $this->prometheus(),
            'fieldConfig' => [
                'defaults' => [
                    'mappings' => [],
                    'max' => 100,
                    'min' => 0,
                    'thresholds' => [
                        'mode' => 'percentage',
                        'steps' => [
                            ['color' => 'green', 'value' => null],
                            ['color' => 'orange', 'value' => $warning],
                            ['color' => 'red', 'value' => $critical],
                        ],
                    ],
                    'unit' => 'percent',
                ],
                'overrides' => [],
            ],
            'gridPos' => ['h' => 4, 'w' => 5, 'x' => $column, 'y' => 0],
            'id' => $id,
            'options' => $this->statOptions(),
            'targets' => [$this->target('A', $expr)],
            'title' => $title,
            'type' => 'stat',
        ];
    }

    /**
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @param  non-empty-list<array<string, mixed>>  $targets
     * @return array<string, mixed>
     */
    private function timeseriesPanel(int $id, string $title, array $gridPos, string $unit, array $targets): array
    {
        return [
            'datasource' => $this->prometheus(),
            'fieldConfig' => [
                'defaults' => [
                    'custom' => [
                        'drawStyle' => 'line',
                        'fillOpacity' => 10,
                        'lineInterpolation' => 'linear',
                        'lineWidth' => 1,
                        'pointSize' => 5,
                        'showPoints' => 'never',
                        'spanNulls' => false,
                    ],
                    'mappings' => [],
                    'thresholds' => [
                        'mode' => 'absolute',
                        'steps' => [['color' => 'green', 'value' => null]],
                    ],
                    'unit' => $unit,
                ],
                'overrides' => [],
            ],
            'gridPos' => $gridPos,
            'id' => $id,
            'options' => [
                'legend' => ['calcs' => ['lastNotNull'], 'displayMode' => 'list', 'placement' => 'bottom'],
                'tooltip' => ['mode' => 'single', 'sort' => 'none'],
            ],
            'targets' => $targets,
            'title' => $title,
            'type' => 'timeseries',
        ];
    }

    /**
     * Seeds the variable with its known options so the panels resolve on first
     * open, before Grafana runs `label_values` against Prometheus.
     *
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    private function nodeVariable(string $selected, array $names): array
    {
        return [
            'current' => ['selected' => true, 'text' => $selected, 'value' => $selected],
            'datasource' => $this->prometheus(),
            'definition' => 'label_values(up{job="orbit-node-exporter"}, node)',
            'hide' => 0,
            'includeAll' => false,
            'label' => 'Node',
            'multi' => false,
            'name' => 'node',
            'options' => array_map(
                static fn (string $name): array => [
                    'selected' => $name === $selected,
                    'text' => $name,
                    'value' => $name,
                ],
                $names,
            ),
            'query' => 'label_values(up{job="orbit-node-exporter"}, node)',
            'refresh' => 1,
            'regex' => '',
            'sort' => 1,
            'type' => 'query',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statOptions(): array
    {
        return [
            'colorMode' => 'value',
            'graphMode' => 'area',
            'justifyMode' => 'auto',
            'orientation' => 'auto',
            'reduceOptions' => ['calcs' => ['lastNotNull'], 'fields' => '', 'values' => false],
            'textMode' => 'auto',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function target(string $refId, string $expr, string $legendFormat = ''): array
    {
        return [
            'datasource' => $this->prometheus(),
            'editorMode' => 'code',
            'expr' => $expr,
            'instant' => false,
            'legendFormat' => $legendFormat,
            'range' => true,
            'refId' => $refId,
        ];
    }

    /**
     * @return array{type: string, uid: string}
     */
    private function prometheus(): array
    {
        return ['type' => 'prometheus', 'uid' => self::Datasource];
    }
}
