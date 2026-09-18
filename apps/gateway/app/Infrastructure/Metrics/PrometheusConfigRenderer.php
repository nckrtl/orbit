<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

final readonly class PrometheusConfigRenderer
{
    /**
     * How often Prometheus scrapes every node exporter. `orbit top` redraws a node's metrics
     * every five seconds, so anything longer than this shows the same sample twice; the cost is
     * one cheap `/proc` read per node per interval (measured at 0.08s-0.18s per scrape).
     */
    public const string ScrapeInterval = '5s';

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
                .':'
                .MetricsFootprint::ExporterPort
                .'"]'
                ."\n        labels:\n          node: \""
                .$node['name']
                ."\"\n";
        }

        return
            '# retention.time: '.MetricsRuntimeSpec::RetentionTime." (configured by the container CLI flag)\nglobal:\n  scrape_interval: ".self::ScrapeInterval."\n  evaluation_interval: ".self::ScrapeInterval."\nscrape_configs:\n  - job_name: orbit-node-exporter\n    static_configs:\n"
            .$entries;
    }
}
