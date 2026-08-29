<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use InvalidArgumentException;
use JsonException;

final readonly class MetricsRuntimeSpec
{
    public const string PrometheusImage = 'prom/prometheus:v3.5.0';

    public const string GrafanaImage = 'grafana/grafana:12.1.1';

    private const string Network = 'orbit-metrics-runtime';

    public function for(
        MetricsService $service,
        int $assignmentId,
        string $wireguardAddress,
        string $configurationHash,
    ): MetricsContainerSpec {
        if (filter_var($wireguardAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Metrics containers require a valid WireGuard IPv4 address.');
        }

        $definition = match ($service) {
            MetricsService::Prometheus => $this->prometheusDefinition(),
            MetricsService::Grafana => $this->grafanaDefinition(),
        };
        $publishedAddress = $service === MetricsService::Prometheus ? '127.0.0.1' : $wireguardAddress;
        $publicSpec = [
            'service' => $service->value,
            'image' => $definition['image'],
            'name' => $definition['name'],
            'volume' => $definition['volume'],
            'network' => self::Network,
            'published_address' => $publishedAddress,
            'command' => $definition['command'],
            'mounts' => $definition['mounts'],
            'environment' => $definition['environment'],
            'health_command' => $definition['health_command'],
            'configuration_hash' => $configurationHash,
        ];
        $specHash = hash('sha256', $this->encode($publicSpec));
        $labels = [
            'com.orbit.managed' => 'metrics',
            'com.orbit.metrics.service' => $service->value,
            'com.orbit.metrics.assignment' => (string) $assignmentId,
            'com.orbit.metrics.spec-hash' => $specHash,
        ];

        return new MetricsContainerSpec(
            service: $service,
            image: $definition['image'],
            name: $definition['name'],
            volume: $definition['volume'],
            network: self::Network,
            publishedAddress: $publishedAddress,
            labels: $labels,
            volumeLabels: [
                'com.orbit.managed' => 'metrics',
                'com.orbit.metrics.volume' => $service->value,
            ],
            networkLabels: [
                'com.orbit.managed' => 'metrics',
                'com.orbit.metrics.network' => 'runtime',
            ],
            command: $definition['command'],
            mounts: $definition['mounts'],
            environment: $definition['environment'],
            healthCommand: $definition['health_command'],
            specHash: $specHash,
        );
    }

    /** @return array{image: string, name: string, volume: string, command: list<string>, mounts: list<string>, environment: array<string, string>, health_command: non-empty-list<string>} */
    private function prometheusDefinition(): array
    {
        return [
            'image' => self::PrometheusImage,
            'name' => 'orbit-metrics-prometheus',
            'volume' => 'orbit-metrics-prometheus-data',
            'command' => [
                '--config.file=/etc/prometheus/prometheus.yml',
                '--storage.tsdb.path=/prometheus',
                '--storage.tsdb.retention.time=15d',
            ],
            'mounts' => [
                '/etc/orbit/metrics/prometheus.yml:/etc/prometheus/prometheus.yml:ro',
            ],
            'environment' => [],
            'health_command' => [
                'CMD',
                'wget',
                '--no-verbose',
                '--tries=1',
                '--spider',
                'http://127.0.0.1:9090/-/ready',
            ],
        ];
    }

    /** @return array{image: string, name: string, volume: string, command: list<string>, mounts: list<string>, environment: array<string, string>, health_command: non-empty-list<string>} */
    private function grafanaDefinition(): array
    {
        return [
            'image' => self::GrafanaImage,
            'name' => 'orbit-metrics-grafana',
            'volume' => 'orbit-metrics-grafana-data',
            'command' => [],
            'mounts' => [
                '/etc/orbit/metrics/grafana/grafana.ini:/etc/grafana/grafana.ini:ro',
                '/etc/orbit/metrics/grafana/provisioning:/etc/grafana/provisioning:ro',
                '/etc/orbit/metrics/grafana/dashboards:/var/lib/grafana/dashboards:ro',
                '/etc/orbit/metrics/grafana/admin-password:/run/orbit/grafana-admin-password:ro',
            ],
            'environment' => [
                'GF_SECURITY_ADMIN_USER' => 'admin',
                'GF_SECURITY_ADMIN_PASSWORD__FILE' => '/run/orbit/grafana-admin-password',
            ],
            'health_command' => [
                'CMD',
                'wget',
                '--no-verbose',
                '--tries=1',
                '--spider',
                'http://127.0.0.1:3000/api/health',
            ],
        ];
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The Metrics runtime specification is invalid.', previous: $exception);
        }
    }
}
