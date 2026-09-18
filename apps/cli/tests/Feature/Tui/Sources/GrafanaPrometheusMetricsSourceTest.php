<?php

declare(strict_types=1);

use App\Support\Tui\Sources\GrafanaPrometheusMetricsSource;
use Illuminate\Support\Facades\Http;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Metrics\MetricsCredentialsResponse;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

function grafana_metrics_credentials(string $url = 'https://metrics.orbit'): MetricsCredentialsResponse
{
    return new MetricsCredentialsResponse($url, 'admin', 'sentinel-password', 'request-id');
}

function grafana_metrics_node(int $id, string $name, ?string $wireguardIp): NodeResponse
{
    return new NodeResponse(
        id: $id,
        name: $name,
        status: 'active',
        publicSshHost: '192.0.2.'.$id,
        publicSshPort: 22,
        user: 'orbit',
        wireguardIp: $wireguardIp,
        roles: [],
        requestId: 'request-id',
    );
}

/** @param  list<NodeResponse>  $nodes */
function grafana_metrics_send(?MetricsCredentialsResponse $credentials, ?array $nodes): Closure
{
    return function (object $request, string $responseClass) use ($credentials, $nodes): object {
        if ($responseClass === MetricsCredentialsResponse::class) {
            if ($credentials === null) {
                throw new GatewayApiException('Metrics is not assigned.', 'metrics.assignment_missing');
            }

            return $credentials;
        }

        if ($responseClass === NodesResponse::class) {
            if ($nodes === null) {
                throw new GatewayApiException('nope', 'gateway.request_failed');
            }

            return new NodesResponse($nodes, 'request-id');
        }

        throw new RuntimeException("Unexpected request for {$responseClass}.");
    };
}

/** A vector response `PrometheusNodeMetricsMapper` groups by the `instance` label. */
function grafana_vector_response(array $samples): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $samples]];
}

function grafana_empty_vector_response(): array
{
    return grafana_vector_response([]);
}

describe(GrafanaPrometheusMetricsSource::class, function (): void {
    it('verifies Grafana against the profile root certificate', function (): void {
        // `metrics.orbit` presents an Orbit CA leaf and PHP verifies against its own bundle, not
        // the operating system trust store, so without this every call failed its TLS handshake
        // and the screen showed "No metrics." for every node.
        $source = new GrafanaPrometheusMetricsSource(
            grafana_metrics_send(grafana_metrics_credentials(), []),
            '/home/orbit/.orbit/ca/root.pem',
        );

        $client = new ReflectionMethod($source, 'client');

        expect($client->invoke($source, grafana_metrics_credentials())->getOptions())
            ->toHaveKey('verify', '/home/orbit/.orbit/ca/root.pem');
    });

    it('leaves verification alone when the profile pins no certificate', function (): void {
        $source = new GrafanaPrometheusMetricsSource(grafana_metrics_send(grafana_metrics_credentials(), []));

        $client = new ReflectionMethod($source, 'client');

        expect($client->invoke($source, grafana_metrics_credentials())->getOptions())
            ->not->toHaveKey('verify');
    });

    it('still reports a snapshot when Prometheus refuses an enriching query', function (): void {
        $instance = '10.44.0.3:9100';
        Http::fake([
            'https://metrics.orbit/api/datasources' => Http::response([
                ['uid' => 'orbit-prometheus', 'type' => 'prometheus'],
            ]),
            'https://metrics.orbit/api/datasources/proxy/uid/orbit-prometheus/api/v1/query*' => function ($request) use ($instance) {
                $query = $request['query'] ?? '';

                // A Prometheus that rejects one query must not blank every Node's metrics.
                if (str_contains($query, 'node_pressure_')) {
                    return Http::response([
                        'status' => 'error',
                        'errorType' => 'execution',
                        'error' => 'vector cannot contain metrics with the same labelset',
                    ], 422);
                }

                if (str_contains($query, 'node_memory_MemTotal_bytes')) {
                    return Http::response(grafana_vector_response([
                        ['metric' => ['__name__' => 'node_memory_MemTotal_bytes', 'instance' => $instance], 'value' => [1.0, '4294967296']],
                        ['metric' => ['__name__' => 'node_memory_MemAvailable_bytes', 'instance' => $instance], 'value' => [1.0, '1073741824']],
                        ['metric' => ['__name__' => 'node_boot_time_seconds', 'instance' => $instance], 'value' => [1.0, '1000']],
                    ]));
                }

                return Http::response(grafana_empty_vector_response());
            },
        ]);
        $source = new GrafanaPrometheusMetricsSource(grafana_metrics_send(
            grafana_metrics_credentials(),
            [grafana_metrics_node(3, 'beast', '10.44.0.3')],
        ));

        $snapshot = $source->forNode(3);

        expect($snapshot)->not->toBeNull()
            ->and($snapshot['mem'][1])->toBe(4.0);
    });

    it('answers null and an empty fleet when Metrics is not assigned', function (): void {
        $source = new GrafanaPrometheusMetricsSource(grafana_metrics_send(null, []));

        expect($source->forNode(1))->toBeNull()
            ->and($source->forFleet())->toBe([]);
    });

    it('answers null for a Node with no WireGuard address, without calling Grafana', function (): void {
        Http::fake(function (): never {
            throw new RuntimeException('Grafana must not be called for a Node with no address.');
        });
        $source = new GrafanaPrometheusMetricsSource(grafana_metrics_send(
            grafana_metrics_credentials(),
            [grafana_metrics_node(1, 'roleless', null)],
        ));

        expect($source->forNode(1))->toBeNull();
    });

    it('maps one Node\'s snapshot in GiB through the Grafana datasource proxy', function (): void {
        Http::fake([
            'https://metrics.orbit/api/datasources' => Http::response([
                ['uid' => 'other', 'type' => 'loki'],
                ['uid' => 'orbit-prometheus', 'type' => 'prometheus'],
            ]),
            'https://metrics.orbit/api/datasources/proxy/uid/orbit-prometheus/api/v1/query*' => function ($request) {
                $query = $request['query'] ?? '';
                $instance = '10.44.0.3:9100';

                if (str_contains($query, 'node_memory_MemTotal_bytes')) {
                    return Http::response(grafana_vector_response([
                        ['metric' => ['__name__' => 'node_memory_MemTotal_bytes', 'instance' => $instance], 'value' => [1.0, '4294967296']],
                        ['metric' => ['__name__' => 'node_memory_MemAvailable_bytes', 'instance' => $instance], 'value' => [1.0, '1073741824']],
                        ['metric' => ['__name__' => 'node_boot_time_seconds', 'instance' => $instance], 'value' => [1.0, (string) (time() - 3_661)]],
                    ]));
                }

                if (str_contains($query, 'node_cpu_seconds_total')) {
                    return Http::response(grafana_vector_response([
                        ['metric' => ['instance' => $instance, 'cpu' => '0'], 'value' => [1.0, '0.5']],
                    ]));
                }

                if (str_contains($query, 'node_filesystem')) {
                    return Http::response(grafana_vector_response([
                        ['metric' => ['__name__' => 'node_filesystem_size_bytes', 'instance' => $instance, 'mountpoint' => '/', 'fstype' => 'ext4'], 'value' => [1.0, '85899345920']],
                        ['metric' => ['__name__' => 'node_filesystem_avail_bytes', 'instance' => $instance, 'mountpoint' => '/', 'fstype' => 'ext4'], 'value' => [1.0, '75161927680']],
                    ]));
                }

                return Http::response(grafana_empty_vector_response());
            },
        ]);
        $source = new GrafanaPrometheusMetricsSource(grafana_metrics_send(
            grafana_metrics_credentials(),
            [grafana_metrics_node(1, 'beast', '10.44.0.3')],
        ));

        $metrics = $source->forNode(1);

        expect($metrics)->not->toBeNull()
            ->and($metrics['cores'])->toBe([0.5])
            ->and($metrics['mem'][0])->toBeGreaterThan(2.9)->toBeLessThan(3.1)
            ->and($metrics['mem'][1])->toBe(4.0)
            ->and($metrics['uptime'])->toBe('1h 1m')
            ->and($metrics['disks'][0][0])->toBe('/');

        Http::assertSent(fn ($request): bool => str_contains((string) ($request['query'] ?? ''), 'instance="10.44.0.3:9100"'));
    });

    it('only lists Nodes Prometheus has samples for in the fleet fetch', function (): void {
        Http::fake([
            'https://metrics.orbit/api/datasources' => Http::response([
                ['uid' => 'orbit-prometheus', 'type' => 'prometheus'],
            ]),
            'https://metrics.orbit/api/datasources/proxy/uid/orbit-prometheus/api/v1/query*' => function ($request) {
                $query = $request['query'] ?? '';

                if (str_contains($query, 'node_memory_MemTotal_bytes')) {
                    return Http::response(grafana_vector_response([
                        ['metric' => ['__name__' => 'node_memory_MemTotal_bytes', 'instance' => '10.44.0.3:9100'], 'value' => [1.0, '4294967296']],
                    ]));
                }

                return Http::response(grafana_empty_vector_response());
            },
        ]);
        $source = new GrafanaPrometheusMetricsSource(grafana_metrics_send(
            grafana_metrics_credentials(),
            [
                grafana_metrics_node(1, 'has-data', '10.44.0.3'),
                grafana_metrics_node(2, 'no-samples', '10.44.0.4'),
            ],
        ));

        $fleet = $source->forFleet();

        expect(array_keys($fleet))->toBe([1]);
    });

    it('answers an empty fleet when Grafana rejects the credential', function (): void {
        Http::fake([
            'https://metrics.orbit/api/datasources' => Http::response(['error' => 'unauthorized'], 401),
        ]);
        $source = new GrafanaPrometheusMetricsSource(grafana_metrics_send(
            grafana_metrics_credentials(),
            [grafana_metrics_node(1, 'beast', '10.44.0.3')],
        ));

        expect($source->forFleet())->toBe([])
            ->and($source->forNode(1))->toBeNull();
    });
});
