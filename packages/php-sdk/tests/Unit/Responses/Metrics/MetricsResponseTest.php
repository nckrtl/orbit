<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Metrics\MetricsCredentialsResponse;
use Orbit\Sdk\Responses\Metrics\MetricsMutationResponse;
use Orbit\Sdk\Responses\Metrics\MetricsStatusResponse;

it('maps bounded metrics status and exporter rows', function (): void {
    $response = MetricsStatusResponse::fromGatewayData([
        'enabled' => true,
        'url' => 'https://metrics.orbit',
        'assignment' => [
            'id' => 7,
            'node_id' => 7,
            'node_name' => 'metrics-node',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ],
        'prometheus' => ['status' => 'healthy'],
        'grafana' => 'healthy',
        'exporters' => [[
            'id' => 7,
            'name' => 'metrics',
            'desired' => true,
            'actual' => 'active',
            'reason' => 'metrics_node',
        ]],
    ], 'req');
    expect($response->enabled)
        ->toBeTrue()
        ->and($response->exporters)
        ->toHaveCount(1)
        ->and($response->exporters[0]['reason'])
        ->toBe('metrics_node')
        ->and($response->assignment['node_name'])
        ->toBe('metrics-node');
});

it('preserves failed metrics assignments for recovery', function (): void {
    $response = MetricsStatusResponse::fromGatewayData([
        'enabled' => true,
        'url' => 'https://metrics.orbit',
        'assignment' => [
            'id' => 7,
            'node_id' => 3,
            'node_name' => 'app-dev',
            'status' => 'failed',
            'failed_step' => 'metrics:runtime',
            'error_code' => 'metrics.runtime_failed',
        ],
        'prometheus' => 'unknown',
        'grafana' => 'unknown',
        'exporters' => [],
    ], 'req');

    expect($response->assignment)->toBe([
        'id' => 7,
        'node_id' => 3,
        'node_name' => 'app-dev',
        'status' => 'failed',
        'failed_step' => 'metrics:runtime',
        'error_code' => 'metrics.runtime_failed',
    ]);
});

it('preserves exporter ownership drift for operator recovery', function (): void {
    $response = MetricsStatusResponse::fromGatewayData([
        'enabled' => true,
        'url' => 'https://metrics.orbit',
        'assignment' => [
            'id' => 7,
            'node_id' => 3,
            'node_name' => 'app-dev',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ],
        'prometheus' => 'healthy',
        'grafana' => 'healthy',
        'exporters' => [[
            'id' => 3,
            'name' => 'app-dev',
            'desired' => true,
            'actual' => 'drift',
            'reason' => 'metrics_node',
        ]],
    ], 'req');

    expect($response->exporters[0]['actual'])->toBe('drift');
});

it('rejects unbounded or incomplete assignment rows', function (): void {
    expect(fn () => MetricsStatusResponse::fromGatewayData([
        'enabled' => true,
        'assignment' => ['id' => 0, 'status' => 'active'],
        'prometheus' => 'healthy',
        'grafana' => 'healthy',
        'exporters' => [],
    ], 'req'))
        ->toThrow(GatewayApiException::class);
});

it('rejects impossible enabled and assignment combinations', function (): void {
    expect(fn () => MetricsStatusResponse::fromGatewayData([
        'enabled' => false,
        'url' => null,
        'assignment' => [
            'id' => 7,
            'node_id' => 3,
            'node_name' => 'app-dev',
            'status' => 'failed',
            'failed_step' => 'metrics:runtime',
            'error_code' => 'metrics.runtime_failed',
        ],
        'prometheus' => 'disabled',
        'grafana' => 'disabled',
        'exporters' => [],
    ], 'req'))
        ->toThrow(GatewayApiException::class, 'invalid metrics status');
});

it('rejects empty credentials', function (): void {
    expect(fn () => MetricsCredentialsResponse::fromGatewayData([
        'url' => '',
        'username' => 'admin',
        'password' => str_repeat('x', times: 8),
    ], 'req'))
        ->toThrow(GatewayApiException::class);
});

it('rejects unbounded credential values', function (): void {
    expect(fn () => MetricsCredentialsResponse::fromGatewayData([
        'url' => 'https://metrics.orbit',
        'username' => 'admin',
        'password' => str_repeat('x', times: 4097),
    ], 'req'))
        ->toThrow(GatewayApiException::class, 'invalid metrics credentials');
});

it('rejects unbounded mutation status values', function (): void {
    expect(fn () => MetricsMutationResponse::fromGatewayData([
        'node_id' => 3,
        'status' => str_repeat('x', times: 256),
    ], 'req'))
        ->toThrow(GatewayApiException::class, 'invalid metrics mutation data');
});

it('keeps credential secrets out of debug and serialization output', function (): void {
    $password = str_repeat('s', times: 16);
    $credentials = MetricsCredentialsResponse::fromGatewayData([
        'url' => 'https://metrics.orbit',
        'username' => 'admin',
        'password' => $password,
    ], 'req');

    expect($credentials->__debugInfo())
        ->toBe(['type' => MetricsCredentialsResponse::class])
        ->and(fn (): string => serialize($credentials))
        ->toThrow(LogicException::class);
});
