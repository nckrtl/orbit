<?php

declare(strict_types=1);

use App\Data\Metrics\MetricsCredentialsData;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\Metrics\GrafanaPrometheusNodeMetricsReader;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

final class FakeGrafanaMetricsCredentialManager implements MetricsCredentialManager
{
    public function __construct(private readonly ?MetricsCredentialsData $data = null) {}

    public function passwordForConvergence(Node $node): string
    {
        throw new RuntimeException('not used in this test');
    }

    public function verifyActive(Node $node): void {}

    public function purge(Node $node): void {}

    public function credentials(): MetricsCredentialsData
    {
        if ($this->data === null) {
            throw new ResourceOperationException('metrics.assignment_missing', 'Metrics is not assigned.', 409);
        }

        return $this->data;
    }

    public function storedCredentials(): MetricsCredentialsData
    {

        return $this->credentials();

    }

    public function reset(): MetricsCredentialsData
    {
        throw new RuntimeException('not used in this test');
    }
}

function grafana_reader_vector(array $samples): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $samples]];
}

function grafana_reader_empty(): array
{
    return grafana_reader_vector([]);
}

function grafana_metrics_role_node(string $wireguardIp = '10.44.0.90'): Node
{
    $node = Node::query()->create([
        'name' => 'metrics-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.90',
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);

    return $node;
}

describe(GrafanaPrometheusNodeMetricsReader::class, function (): void {
    it('answers node.metrics_unreachable for a Node with no WireGuard address, without calling Grafana', function (): void {
        Http::fake(function (): never {
            throw new RuntimeException('Grafana must not be called for a Node with no address.');
        });
        app()->instance(MetricsCredentialManager::class, new FakeGrafanaMetricsCredentialManager);
        $reader = app(GrafanaPrometheusNodeMetricsReader::class);

        $call = fn () => $reader->read(new Node(['name' => 'roleless']));
        expect($call)->toThrow(ResourceOperationException::class);

        try {
            $call();
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('node.metrics_unreachable');
        }
    });

    it('propagates metrics.assignment_missing when no Node carries the Metrics role', function (): void {
        app()->instance(MetricsCredentialManager::class, new FakeGrafanaMetricsCredentialManager);
        $reader = app(GrafanaPrometheusNodeMetricsReader::class);

        $target = Node::query()->create([
            'name' => 'target',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.60',
            'wireguard_ip' => '10.44.0.60',
            'user' => 'orbit',
        ]);
        $call = fn () => $reader->read($target);

        expect($call)->toThrow(ResourceOperationException::class);

        try {
            $call();
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('metrics.assignment_missing')
                ->and($exception->status)->toBe(409);
        }
    });

    it('queries the Metrics Node\'s Grafana directly and maps the snapshot for the requested Node', function (): void {
        grafana_metrics_role_node('10.44.0.90');
        $target = Node::query()->create([
            'name' => 'beast',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.3',
            'wireguard_ip' => '10.44.0.3',
            'user' => 'orbit',
        ]);
        $credentials = new MetricsCredentialsData('https://metrics.orbit', 'admin', 'sentinel-password');
        app()->instance(MetricsCredentialManager::class, new FakeGrafanaMetricsCredentialManager($credentials));

        Http::fake([
            'http://10.44.0.90:3000/api/datasources' => Http::response([
                ['uid' => 'orbit-prometheus', 'type' => 'prometheus'],
            ]),
            'http://10.44.0.90:3000/api/datasources/proxy/uid/orbit-prometheus/api/v1/query*' => function ($request) {
                $query = $request['query'] ?? '';

                if (str_contains($query, 'node_memory_MemTotal_bytes')) {
                    return Http::response(grafana_reader_vector([
                        ['metric' => ['__name__' => 'node_memory_MemTotal_bytes', 'instance' => '10.44.0.3:9100'], 'value' => [1.0, '4294967296']],
                    ]));
                }

                return Http::response(grafana_reader_empty());
            },
        ]);

        $reader = app(GrafanaPrometheusNodeMetricsReader::class);
        $snapshot = $reader->read($target);

        expect($snapshot['memory']['total'])->toBe(4_294_967_296);

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '10.44.0.90:3000')
            && str_contains((string) ($request->data()['Authorization'] ?? $request->header('Authorization')[0] ?? ''), 'Basic'));
    });

    it('answers node.metrics_unreachable when Grafana has no Prometheus datasource', function (): void {
        grafana_metrics_role_node('10.44.0.91');
        $target = Node::query()->create([
            'name' => 'beast',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.4',
            'wireguard_ip' => '10.44.0.4',
            'user' => 'orbit',
        ]);
        app()->instance(MetricsCredentialManager::class, new FakeGrafanaMetricsCredentialManager(
            new MetricsCredentialsData('https://metrics.orbit', 'admin', 'sentinel-password'),
        ));
        Http::fake([
            'http://10.44.0.91:3000/api/datasources' => Http::response([
                ['uid' => 'loki', 'type' => 'loki'],
            ]),
        ]);

        $reader = app(GrafanaPrometheusNodeMetricsReader::class);
        $call = fn () => $reader->read($target);

        expect($call)->toThrow(ResourceOperationException::class);

        try {
            $call();
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('node.metrics_unreachable')
                ->and($exception->status)->toBe(502);
        }
    });

    it('answers node.metrics_unreachable when Prometheus has no samples for this Node', function (): void {
        grafana_metrics_role_node('10.44.0.92');
        $target = Node::query()->create([
            'name' => 'quiet',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.5',
            'wireguard_ip' => '10.44.0.5',
            'user' => 'orbit',
        ]);
        app()->instance(MetricsCredentialManager::class, new FakeGrafanaMetricsCredentialManager(
            new MetricsCredentialsData('https://metrics.orbit', 'admin', 'sentinel-password'),
        ));
        Http::fake([
            'http://10.44.0.92:3000/api/datasources' => Http::response([
                ['uid' => 'orbit-prometheus', 'type' => 'prometheus'],
            ]),
            'http://10.44.0.92:3000/api/datasources/proxy/uid/orbit-prometheus/api/v1/query*' => Http::response(grafana_reader_empty()),
        ]);

        $reader = app(GrafanaPrometheusNodeMetricsReader::class);
        $call = fn () => $reader->read($target);

        expect($call)->toThrow(ResourceOperationException::class);

        try {
            $call();
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('node.metrics_unreachable');
        }
    });
});
