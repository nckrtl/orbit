<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use InvalidArgumentException;
use JsonException;
use SensitiveParameter;

final readonly class MetricsConfigurationRenderer
{
    public function __construct(
        private PrometheusConfigRenderer $prometheus = new PrometheusConfigRenderer,
        private GrafanaConfigRenderer $grafana = new GrafanaConfigRenderer,
        private NodeResourcesDashboardRenderer $dashboard = new NodeResourcesDashboardRenderer,
    ) {}

    /** @param list<array{name: string, address: string}> $targets */
    public function render(array $targets, #[SensitiveParameter] string $password): MetricsConfigurationBundle
    {
        if ($password === '') {
            throw new InvalidArgumentException('The Grafana admin password is unavailable.');
        }

        $publicFiles = [
            '/etc/orbit/metrics/prometheus.yml' => $this->prometheus->render($targets),
            '/etc/orbit/metrics/grafana/grafana.ini' => $this->grafana->rootUrl(),
            '/etc/orbit/metrics/grafana/provisioning/datasources/prometheus.yml' => $this->grafana->datasource(),
            '/etc/orbit/metrics/grafana/provisioning/dashboards/provider.yml' => $this->grafana->dashboardProvider(),
            '/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json' => $this->dashboard->render(
                array_map(static fn (array $target): string => $target['name'], $targets),
            ),
        ];
        $files = [];

        foreach ($publicFiles as $path => $contents) {
            // Prometheus (nobody) and Grafana (472) read these bind mounts inside
            // their containers, so the public files stay world-readable.
            $files[] = new MetricsGeneratedFile($path, new ProtectedMetricsSecret($contents), mode: 0o644);
        }

        $files[] = new MetricsGeneratedFile(
            path: '/etc/orbit/metrics/grafana/admin-password',
            contents: new ProtectedMetricsSecret($password),
            mode: 0o400,
            owner: '472',
            group: '472',
        );

        return new MetricsConfigurationBundle(
            files: $files,
            publicHash: hash('sha256', $this->encode($publicFiles)),
        );
    }

    /** @param array<string, string> $files */
    private function encode(array $files): string
    {
        try {
            return json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The generated Metrics configuration is invalid.', previous: $exception);
        }
    }
}
