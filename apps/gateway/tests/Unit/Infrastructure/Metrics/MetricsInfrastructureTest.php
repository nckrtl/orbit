<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\GrafanaConfigRenderer;
use App\Infrastructure\Metrics\MetricsPublicationRenderer;
use App\Infrastructure\Metrics\MetricsRuntimeSpec;
use App\Infrastructure\Metrics\MetricsService;
use App\Infrastructure\Metrics\NodeResourcesDashboardRenderer;
use App\Infrastructure\Metrics\PrometheusConfigRenderer;
use App\Infrastructure\Metrics\ProtectedMetricsSecret;

it('renders owned pinned runtime specifications', function (): void {
    $runtime = new MetricsRuntimeSpec;
    $prometheus = $runtime->for(MetricsService::Prometheus, 41, '10.44.0.3', 'configuration');
    $grafana = $runtime->for(MetricsService::Grafana, 41, '10.44.0.3', 'configuration');

    expect($prometheus->name)
        ->toBe('orbit-metrics-prometheus')
        ->and($prometheus->volume)
        ->toBe('orbit-metrics-prometheus-data')
        ->and($prometheus->image)
        ->toBe(MetricsRuntimeSpec::PrometheusImage)
        ->and($prometheus->command)
        ->toContain('--storage.tsdb.retention.time=15d')
        ->and($grafana->labels['com.orbit.managed'])
        ->toBe('metrics');
});
it('renders stable prometheus targets and labels', function (): void {
    $config = new PrometheusConfigRenderer()->render([
        ['name' => 'zulu', 'address' => '10.0.0.2'],
        ['name' => 'alpha', 'address' => '10.0.0.1'],
    ]);
    expect($config)
        ->toContain('retention.time: 15d')
        ->and(strpos($config, '"alpha"'))
        ->toBeLessThan(strpos($config, '"zulu"'));
});
it('renders grafana, publication, and secret contracts', function (): void {
    expect(new GrafanaConfigRenderer()->datasource())
        ->toBe(<<<'YAML'
            apiVersion: 1
            deleteDatasources:
              - name: Prometheus
                orgId: 1
            prune: true
            datasources:
              - name: orbit-prometheus
                type: prometheus
                uid: orbit-prometheus
                orgId: 1
                version: 1
                url: http://127.0.0.1:9090
                access: proxy
                isDefault: true

            YAML)
        ->and(new GrafanaConfigRenderer()->dashboardProvider())
        ->toBe(<<<'YAML'
            apiVersion: 1
            providers:
              - name: Orbit
                type: file
                folder: Orbit
                folderUid: orbit
                disableDeletion: true
                allowUiUpdates: false
                updateIntervalSeconds: 10
                options:
                  path: /var/lib/grafana/dashboards

            YAML)
        ->and(new MetricsPublicationRenderer()->caddy('10.0.0.3'))
        ->toContain('metrics.orbit')
        ->and(new NodeResourcesDashboardRenderer()->render())
        ->toContain('Orbit Node Resources')
        ->and(new ProtectedMetricsSecret('secret')->__debugInfo()['value'])
        ->toBe('[PROTECTED]');
});

it('rejects unsafe metrics publication inputs', function (): void {
    $renderer = new MetricsPublicationRenderer;

    expect(fn () => $renderer->caddy('10.0.0.3', '10.0.0.1; rm -rf /'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $renderer->caddy('10.0.0.3', '10.0.0.1', '/tmp/cert.pem'))
        ->toThrow(InvalidArgumentException::class);
});

it('renders publication bound to the gateway address and private certificate pair', function (): void {
    $config = new MetricsPublicationRenderer()->caddy('10.44.0.2', '10.44.0.1');

    expect($config)
        ->toStartWith("# Managed by Orbit: metrics\n")
        ->toContain('bind 10.44.0.1')
        ->and($config)
        ->toContain('/etc/caddy/orbit-metrics-cert-current/metrics.pem')
        ->and($config)
        ->toContain('reverse_proxy http://10.44.0.2:3000');
});
