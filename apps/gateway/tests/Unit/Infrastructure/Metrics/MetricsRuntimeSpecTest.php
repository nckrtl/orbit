<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\MetricsRuntimeSpec;
use App\Infrastructure\Metrics\MetricsService;

describe(MetricsRuntimeSpec::class, function (): void {
    it('builds pinned owned service and volume specifications', function (): void {
        $spec = new MetricsRuntimeSpec;

        $prometheus = $spec->for(
            MetricsService::Prometheus,
            assignmentId: 41,
            wireguardAddress: '10.44.0.3',
            configurationHash: 'prometheus-config-hash',
        );
        $grafana = $spec->for(
            MetricsService::Grafana,
            assignmentId: 41,
            wireguardAddress: '10.44.0.3',
            configurationHash: 'grafana-config-hash',
        );

        expect($prometheus->image)
            ->toBe('prom/prometheus:v3.5.0')
            ->and($prometheus->name)
            ->toBe('orbit-metrics-prometheus')
            ->and($prometheus->volume)
            ->toBe('orbit-metrics-prometheus-data')
            ->and($prometheus->command)
            ->toContain('--web.listen-address=127.0.0.1:9090')
            ->and($prometheus->labels)
            ->toMatchArray([
                'com.orbit.managed' => 'metrics',
                'com.orbit.metrics.service' => 'prometheus',
                'com.orbit.metrics.assignment' => '41',
            ])
            ->and($prometheus->specHash)
            ->toMatch('/^[a-f0-9]{64}$/')
            ->and($grafana->image)
            ->toBe('grafana/grafana:12.1.1')
            ->and($grafana->environment)
            ->toMatchArray([
                'GF_SERVER_HTTP_ADDR' => '10.44.0.3',
                'GF_SERVER_HTTP_PORT' => '3000',
            ])
            ->and($grafana->healthCommand)
            ->toContain('http://10.44.0.3:3000/api/health')
            ->and($grafana->labels['com.orbit.metrics.spec-hash'])
            ->toBe($grafana->specHash);
    });

    it('changes the spec hash when generated configuration changes', function (): void {
        $spec = new MetricsRuntimeSpec;

        $first = $spec->for(MetricsService::Prometheus, 41, '10.44.0.3', 'first');
        $second = $spec->for(MetricsService::Prometheus, 41, '10.44.0.3', 'second');

        expect($first->specHash)->not->toBe($second->specHash);
    });

    it('bounds container logging and re-converges when the policy changes', function (): void {
        $spec = new MetricsRuntimeSpec;
        $narrower = new MetricsRuntimeSpec(logOptions: ['max-size' => '1m', 'max-file' => '3']);

        $prometheus = $spec->for(MetricsService::Prometheus, 41, '10.44.0.3', 'config');
        $grafana = $spec->for(MetricsService::Grafana, 41, '10.44.0.3', 'config');

        expect($prometheus->logDriver)
            ->toBe('json-file')
            ->and($prometheus->logOptions)
            ->toBe(['max-size' => '10m', 'max-file' => '3'])
            ->and($grafana->logDriver)
            ->toBe($prometheus->logDriver)
            ->and($grafana->logOptions)
            ->toBe($prometheus->logOptions)
            ->and($narrower->for(MetricsService::Prometheus, 41, '10.44.0.3', 'config')->specHash)
            ->not->toBe($prometheus->specHash);
    });

    it('rejects an unsafe metrics address before building container arguments', function (): void {
        expect(
            fn () => new MetricsRuntimeSpec()->for(
                MetricsService::Grafana,
                41,
                "10.44.0.3\n--publish=0.0.0.0:3000:3000",
                'config',
            ),
        )
            ->toThrow(InvalidArgumentException::class, 'valid WireGuard IPv4');
    });
});
