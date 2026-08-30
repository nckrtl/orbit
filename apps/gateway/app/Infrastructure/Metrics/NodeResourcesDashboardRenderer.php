<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

final readonly class NodeResourcesDashboardRenderer
{
    public function render(): string
    {
        return json_encode([
            'title' => 'Orbit Node Resources',
            'uid' => 'orbit-node-resources',
            'templating' => [
                'list' => [[
                    'name' => 'node',
                    'label' => 'Node',
                    'type' => 'query',
                    'datasource' => ['type' => 'prometheus', 'uid' => 'orbit-prometheus'],
                    'query' => 'label_values(up{job="orbit-node-exporter"}, node)',
                ]],
            ],
            'panels' => [[
                'type' => 'stat',
                'title' => 'Exporter Up',
                'targets' => [['expr' => 'up{job="orbit-node-exporter",node=~"$node"}']],
            ]],
            'schemaVersion' => 39,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
