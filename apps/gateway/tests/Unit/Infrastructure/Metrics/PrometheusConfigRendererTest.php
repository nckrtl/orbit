<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\MetricsFootprint;
use App\Infrastructure\Metrics\PrometheusConfigRenderer;
use App\Infrastructure\Metrics\PrometheusProcessMetricsQueries;
use Symfony\Component\Yaml\Yaml;

it('renders both the node exporter and cadvisor scrape jobs, on their own ports at the global interval', function (): void {
    $nodes = [
        ['name' => 'zulu', 'address' => '10.0.0.2'],
        ['name' => 'alpha', 'address' => '10.0.0.1'],
    ];
    $rendered = new PrometheusConfigRenderer()->render($nodes);
    $parsed = Yaml::parse($rendered);

    expect($parsed['global']['scrape_interval'])
        ->toBe('10s')
        ->and($parsed['scrape_configs'])
        ->toHaveCount(2);

    $exporterJob = $parsed['scrape_configs'][0];
    $cadvisorJob = $parsed['scrape_configs'][1];

    expect($exporterJob['job_name'])
        ->toBe('orbit-node-exporter')
        ->and($exporterJob)
        ->not->toHaveKey('scrape_interval')
        ->and($cadvisorJob['job_name'])
        ->toBe('orbit-cadvisor')
        ->and($cadvisorJob)
        ->not->toHaveKey('scrape_interval');

    foreach ([$exporterJob, $cadvisorJob] as $job) {
        expect(array_column($job['static_configs'], 'targets'))
            ->toBe([['10.0.0.1:'.($job['job_name'] === 'orbit-cadvisor' ? MetricsFootprint::CadvisorPort : MetricsFootprint::ExporterPort)], ['10.0.0.2:'.($job['job_name'] === 'orbit-cadvisor' ? MetricsFootprint::CadvisorPort : MetricsFootprint::ExporterPort)]]);
    }
});

it('scrapes cadvisor on the same Nodes the node exporter targets, just a different port', function (): void {
    $nodes = [['name' => 'beast', 'address' => '10.44.0.5']];
    $parsed = Yaml::parse(new PrometheusConfigRenderer()->render($nodes));

    [$exporterJob, $cadvisorJob] = $parsed['scrape_configs'];

    expect($exporterJob['static_configs'][0]['labels']['node'])
        ->toBe('beast')
        ->and($exporterJob['static_configs'][0]['targets'])
        ->toBe(['10.44.0.5:9100'])
        ->and($cadvisorJob['static_configs'][0]['labels']['node'])
        ->toBe('beast')
        ->and($cadvisorJob['static_configs'][0]['targets'])
        ->toBe(['10.44.0.5:9102']);
});

it('rejects an unsafe target for either job', function (): void {
    $renderer = new PrometheusConfigRenderer;

    expect(fn () => $renderer->render([['name' => 'evil"; rm -rf /', 'address' => '10.0.0.1']]))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps the cadvisor CPU rate window wide enough for the scrape interval to fill it', function (): void {
    $window = (int) rtrim(PrometheusProcessMetricsQueries::CpuRateWindow, 's');
    $scrape = (int) rtrim(PrometheusConfigRenderer::ScrapeInterval, 's');

    expect(intdiv($window, $scrape))->toBeGreaterThanOrEqual(4);
});
