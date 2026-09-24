<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use Symfony\Component\Yaml\Yaml;

final readonly class PrometheusConfigRenderer
{
    /**
     * How often Prometheus scrapes every node exporter and cAdvisor. A scrape only serializes
     * values each exporter already collects, measured at about 0.03s for cAdvisor and 0.1s for
     * the node exporter. The web dashboard refreshes on the same ten seconds, so
     * no reader polls faster than new samples arrive.
     */
    public const string ScrapeInterval = '10s';

    /** @param list<array{name:string,address:string}> $nodes
     * @param  list<ServiceMetricsNode>  $services
     */
    public function render(array $nodes, array $services = []): string
    {
        usort($nodes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $this->guardTargets($nodes);

        return
            '# retention.time: '.MetricsRuntimeSpec::RetentionTime." (configured by the container CLI flag)\nglobal:\n  scrape_interval: ".self::ScrapeInterval."\n  evaluation_interval: ".self::ScrapeInterval."\nscrape_configs:\n"
            .$this->job('orbit-node-exporter', $nodes, MetricsFootprint::ExporterPort, null)
            .$this->job('orbit-cadvisor', $nodes, MetricsFootprint::CadvisorPort, null)
            .$this->serviceJobs($services);
    }

    /** @param list<ServiceMetricsNode> $targets */
    private function serviceJobs(array $targets): string
    {
        $jobs = [];
        foreach ($targets as $target) {
            $address = (string) $target->node->wireguard_ip;
            $this->guardTargets([['name' => $target->node->name, 'address' => $address]]);
            foreach (['caddy' => $target->caddy, 'fpm' => $target->fpm && $target->instances !== []] as $kind => $enabled) {
                if (! $enabled) {
                    continue;
                }
                $port = $kind === 'caddy' ? ServiceMetricsConfigRenderer::CaddyPort : ServiceMetricsConfigRenderer::FpmPort;
                $rules = [];
                if ($kind === 'caddy') {
                    $hosts = array_map(static fn (string $host): string => preg_quote($host, '/'), $target->hosts);
                    $rules[] = ['source_labels' => ['__name__'], 'regex' => 'caddy_http_.*|caddy_reverse_proxy_upstreams_healthy', 'action' => 'keep'];
                    $rules[] = ['source_labels' => ['host'], 'regex' => $hosts === [] ? '' : '('.implode('|', $hosts).')?', 'action' => 'keep'];
                } else {
                    $rules[] = ['source_labels' => ['__name__'], 'regex' => 'phpfpm_process_.*', 'action' => 'drop'];
                    foreach ($target->instances as $instance) {
                        $pool = ProductionPhpRuntimeIdentity::from($instance)->pool;
                        $rules[] = ['source_labels' => ['pool'], 'regex' => preg_quote($pool, '/'), 'target_label' => 'app_instance_id', 'replacement' => (string) $instance->id];
                    }
                }
                $jobs[] = [
                    'job_name' => 'orbit-'.$kind.'-'.$target->node->id,
                    'scrape_interval' => '15s',
                    'scrape_timeout' => '12s',
                    'sample_limit' => 20000,
                    'static_configs' => [[
                        'targets' => [$address.':'.$port],
                        'labels' => ['node' => $target->node->name, 'node_id' => (string) $target->node->id, 'service' => $kind],
                    ]],
                    'metric_relabel_configs' => $rules,
                ];
            }
        }
        if ($jobs === []) {
            return '';
        }

        return preg_replace('/^/m', '  ', rtrim(Yaml::dump($jobs, 10, 2)))."\n";
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
