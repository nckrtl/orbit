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

    /**
     * How often Prometheus scrapes cAdvisor. Process CPU and memory do not need `orbit top`'s
     * five-second node resolution, and cAdvisor is the expensive job (see
     * `MetricsFootprint::CadvisorDisabledMetrics`), so it is scraped six times less often than the
     * node exporter. `PrometheusProcessMetricsQueries::CpuRateWindow` is sized off this interval.
     */
    public const string CadvisorScrapeInterval = '30s';

    /** @param list<array{name:string,address:string}> $nodes */
    public function render(array $nodes): string
    {
        usort($nodes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $this->guardTargets($nodes);

        return
            '# retention.time: '.MetricsRuntimeSpec::RetentionTime." (configured by the container CLI flag)\nglobal:\n  scrape_interval: ".self::ScrapeInterval."\n  evaluation_interval: ".self::ScrapeInterval."\nscrape_configs:\n"
            .$this->job('orbit-node-exporter', $nodes, MetricsFootprint::ExporterPort, null)
            .$this->job('orbit-cadvisor', $nodes, MetricsFootprint::CadvisorPort, self::CadvisorScrapeInterval);
    }

    /** @param list<array{name:string,address:string}> $nodes */
    private function job(string $name, array $nodes, string $port, ?string $scrapeInterval): string
    {
        $entries = '';

        foreach ($nodes as $node) {
            $entries .=
                '      - targets: ["'
                .$node['address']
                .':'
                .$port
                .'"]'
                ."\n        labels:\n          node: \""
                .$node['name']
                ."\"\n";
        }

        $interval = $scrapeInterval === null ? '' : "    scrape_interval: {$scrapeInterval}\n";

        return "  - job_name: {$name}\n{$interval}    static_configs:\n{$entries}";
    }

    /** @param list<array{name:string,address:string}> $nodes */
    private function guardTargets(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (
                ! preg_match('/^[A-Za-z0-9._:-]+$/', $node['address'])
                || ! preg_match('/^[A-Za-z0-9._-]+$/', $node['name'])
            ) {
                throw new \InvalidArgumentException('Invalid metrics target.');
            }
        }
    }
}
