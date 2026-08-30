<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

final readonly class PrometheusConfigRenderer
{
    /** @param list<array{name:string,address:string}> $nodes */
    public function render(array $nodes): string
    {
        usort($nodes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $entries = '';
        foreach ($nodes as $node) {
            if (
                ! preg_match('/^[A-Za-z0-9._:-]+$/', $node['address'])
                || ! preg_match('/^[A-Za-z0-9._-]+$/', $node['name'])
            ) {
                throw new \InvalidArgumentException('Invalid metrics target.');
            }
            $entries .=
                '      - targets: ["'
                .$node['address']
                .':9100"]'
                ."\n        labels:\n          node: \""
                .$node['name']
                ."\"\n";
        }

        return (
            "# retention.time: 15d (configured by the container CLI flag)\nglobal:\n  scrape_interval: 15s\n  evaluation_interval: 15s\nscrape_configs:\n  - job_name: orbit-node-exporter\n    static_configs:\n"
            .$entries
        );
    }
}
