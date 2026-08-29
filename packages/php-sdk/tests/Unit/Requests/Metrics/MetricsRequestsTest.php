<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\Metrics\DisableMetricsExporterRequest;
use Orbit\Sdk\Requests\Metrics\DisableMetricsRequest;
use Orbit\Sdk\Requests\Metrics\EnableMetricsExporterRequest;
use Orbit\Sdk\Requests\Metrics\EnableMetricsRequest;
use Orbit\Sdk\Requests\Metrics\ResetMetricsCredentialsRequest;
use Orbit\Sdk\Requests\Metrics\ShowMetricsCredentialsRequest;
use Orbit\Sdk\Requests\Metrics\ShowMetricsStatusRequest;
use Saloon\Enums\Method;

it('exposes the seven metrics routes', function (string $class, Method $method, string $path): void {
    $request = match ($class) {
        EnableMetricsRequest::class => new $class(7),
        DisableMetricsRequest::class => new $class,
        EnableMetricsExporterRequest::class, DisableMetricsExporterRequest::class => new $class(7),
        default => new $class,
    };
    expect($request->getMethod())->toBe($method)->and($request->resolveEndpoint())->toBe($path);
})->with([
    [EnableMetricsRequest::class,           Method::POST,   '/api/v1/metrics'],
    [DisableMetricsRequest::class,          Method::DELETE, '/api/v1/metrics'],
    [ShowMetricsStatusRequest::class,       Method::GET,    '/api/v1/metrics/status'],
    [ShowMetricsCredentialsRequest::class,  Method::GET,    '/api/v1/metrics/credentials'],
    [ResetMetricsCredentialsRequest::class, Method::POST,   '/api/v1/metrics/credentials/reset'],
    [EnableMetricsExporterRequest::class,   Method::PUT,    '/api/v1/metrics/exporters/7'],
    [DisableMetricsExporterRequest::class,  Method::DELETE, '/api/v1/metrics/exporters/7'],
]);

it('sends enable and disable payloads', function (): void {
    expect(new EnableMetricsRequest(7)->body()->all())
        ->toBe(['node_id' => 7])
        ->and(new DisableMetricsRequest(force: true, purgeData: true)->body()->all())
        ->toBe(['force' => true, 'purge_data' => true]);
});
