<?php

declare(strict_types=1);

use App\Data\Metrics\MetricsCredentialsData;
use App\Data\Metrics\MetricsMutationData;
use App\Data\Metrics\MetricsStatusData;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsPublicationCleanup;
use App\Domain\Metrics\MetricsRoleManager;
use App\Domain\Metrics\MetricsStatusReader;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Api\MetricsController;
use App\Models\Node;

it('exposes the seven focused metrics routes with stable methods', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn (\Illuminate\Routing\Route $route): bool => str_starts_with(
            (string) $route->getName(),
            'metrics:',
        ))
        ->mapWithKeys(static fn (\Illuminate\Routing\Route $route): array => [
            $route->getName() => [$route->uri(), $route->methods()],
        ])
        ->all();

    expect($routes)->toBe([
        'metrics:enable' => ['api/v1/metrics', ['POST']],
        'metrics:remove' => ['api/v1/metrics', ['DELETE']],
        'metrics:status' => ['api/v1/metrics/status', ['GET', 'HEAD']],
        'metrics:credentials' => ['api/v1/metrics/credentials', ['GET', 'HEAD']],
        'metrics:credentials:reset' => ['api/v1/metrics/credentials/reset', ['POST']],
        'metrics:exporter:enable' => ['api/v1/metrics/exporters/{node}', ['PUT']],
        'metrics:exporter:disable' => ['api/v1/metrics/exporters/{node}', ['DELETE']],
    ]);
});

it('requires Gateway authorization for every metrics action', function (): void {
    $reflection = new ReflectionClass(MetricsController::class);
    $attribute = $reflection->getAttributes(RequiresNodeAccess::class)[0]->newInstance();

    expect($attribute->servingNode)->toBe(ServingNode::Gateway);
});

it('rejects enable payloads with missing or unsupported fields', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($gateway);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/metrics', ['unexpected' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.body.0', 'The request body contains unsupported top-level keys.');
});

it('rejects purge requests unless force is true', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($gateway);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->deleteJson('/api/v1/metrics', ['purge_data' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.force.0', 'The force field must be true when purge data is requested.');
});

it('returns status data through the API envelope', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($gateway);
    app()->instance(MetricsStatusReader::class, new class implements MetricsStatusReader {
        public function status(): MetricsStatusData
        {
            return new MetricsStatusData(false, null, null, 'http://prom', 'http://grafana', []);
        }
    });

    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/metrics/status')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonStructure(['data', 'meta' => ['request_id']]);
});

it('allows the active Gateway caller without a directed grant', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($gateway);
    app()->instance(MetricsStatusReader::class, new class implements MetricsStatusReader {
        public function status(): MetricsStatusData
        {
            return new MetricsStatusData(false, null, null, '', '', []);
        }
    });

    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/metrics/status')
        ->assertOk();
});

it('fails closed before reading Metrics state when active Gateway authority drifts', function (): void {
    $first = Node::query()->create([
        'name' => 'gateway-first',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $second = Node::query()->create([
        'name' => 'gateway-second',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($first);
    $this->markAsGateway($second);
    $reader = Mockery::mock(MetricsStatusReader::class);
    $reader->shouldNotReceive('status');
    app()->instance(MetricsStatusReader::class, $reader);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $first->wireguard_ip])
        ->getJson('/api/v1/metrics/status')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');
});

it('allows a caller with a directed grant to the active Gateway', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $caller = Node::query()->create([
        'name' => 'caller',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($gateway);
    $caller->accessibleNodes()->attach($gateway);
    app()->instance(MetricsStatusReader::class, new class implements MetricsStatusReader {
        public function status(): MetricsStatusData
        {
            return new MetricsStatusData(false, null, null, 'disabled', 'disabled', []);
        }
    });

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->getJson('/api/v1/metrics/status')
        ->assertOk()
        ->assertJsonPath('data.enabled', false);
});

it('rejects access to only a Metrics or exporter node before reading status', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $metrics = Node::query()->create([
        'name' => 'metrics',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $caller = Node::query()->create([
        'name' => 'caller',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.3',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->markAsGateway($gateway);
    $caller->accessibleNodes()->attach($metrics);
    $reader = Mockery::mock(MetricsStatusReader::class);
    $reader->shouldNotReceive('status');
    app()->instance(MetricsStatusReader::class, $reader);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->getJson('/api/v1/metrics/status')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');
});

it('passes focused enable and purge mutations to the Metrics role manager', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $target = Node::query()->create([
        'name' => 'target',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($gateway);
    $manager = Mockery::mock(MetricsRoleManager::class);
    $manager
        ->shouldReceive('enable')
        ->once()
        ->with($target->id)
        ->andReturn(new MetricsMutationData($target->id, 'active'));
    $manager
        ->shouldReceive('remove')
        ->once()
        ->with(true, true)
        ->andReturn(new MetricsMutationData($target->id, 'removed', MetricsPublicationCleanup::Uncleaned));
    app()->instance(MetricsRoleManager::class, $manager);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/metrics', ['node_id' => $target->id])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->deleteJson('/api/v1/metrics', ['force' => true, 'purge_data' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'removed')
        ->assertJsonPath('data.publication', 'uncleaned');
});

it('rejects unauthorized metrics requests before reading credentials', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $caller = Node::query()->create([
        'name' => 'caller',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($gateway);
    $manager = Mockery::mock(MetricsCredentialManager::class);
    $manager->shouldReceive('credentials')->never();
    app()->instance(MetricsCredentialManager::class, $manager);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->getJson('/api/v1/metrics/credentials')
        ->assertForbidden();
});

it('marks credentials responses as non-cacheable', function (string $method, string $uri): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($gateway);
    $manager = Mockery::mock(MetricsCredentialManager::class);
    $manager
        ->shouldReceive($method === 'GET' ? 'credentials' : 'reset')
        ->once()
        ->andReturn(new MetricsCredentialsData('https://metrics', 'user', 'sentinel'));
    app()->instance(MetricsCredentialManager::class, $manager);

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->withHeaders(['Content-Type' => 'application/json'])
        ->call($method, $uri, [], [], [], [], $method === 'POST' ? '{}' : '');

    $response
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.password', 'sentinel');
})->with([
    'show' => ['GET', '/api/v1/metrics/credentials'],
    'reset' => ['POST', '/api/v1/metrics/credentials/reset'],
]);

it('refuses to disable the exporter on the Metrics node with a stable error code', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($gateway);
    $metricsNode = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $metricsNode->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->deleteJson("/api/v1/metrics/exporters/{$metricsNode->id}")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'node.role_conflict')
        ->assertJsonPath('error.message', 'The metrics node exporter cannot be disabled.');
});

it('refuses a second Metrics claim through the generic role route with a stable error code', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($gateway);
    $held = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $held->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
    $other = Node::query()->create([
        'name' => 'app-prod',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.3',
        'wireguard_ip' => '10.44.0.3',
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson("/api/v1/nodes/{$other->id}/roles", ['role' => 'metrics'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');
});
