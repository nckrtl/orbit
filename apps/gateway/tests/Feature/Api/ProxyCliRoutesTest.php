<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Nodes\RoleName;
use App\Domain\ProxyCli\ProxyCliAccount;
use App\Domain\ProxyCli\ProxyCliCache;
use App\Domain\ProxyCli\ProxyCliManagementClient;
use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\ProxyCli\ProxyCliSnapshotStore;
use App\Domain\ProxyCli\ProxyCliUsageResponse;
use App\Domain\ProxyCli\ProxyCliWindow;
use App\Domain\Shared\LifecycleStatus;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Api\ProxyCliController;
use App\Infrastructure\ProxyCli\RecordingProxyCliPublicationManager;
use App\Infrastructure\ProxyCli\RecordingProxyCliRuntimeLifecycle;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;

function proxycli_gateway(): Node
{
    $node = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    test()->markAsGateway($node);

    return $node;
}

function proxycli_node(string $name = 'beast', string $ip = '10.44.0.8'): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.8',
        'wireguard_ip' => $ip,
    ]);
}

function proxycli_valkey(Node $node, string $slug = 'valkey'): DatabaseConnection
{
    $node->roles()->create(['role' => RoleName::Database, 'status' => LifecycleStatus::Active]);

    return DatabaseConnection::query()->create([
        'slug' => $slug,
        'driver' => DatabaseDriver::Redis,
        'node_id' => $node->id,
        'host' => $node->wireguard_ip,
        'port' => 6379,
        'database' => '0',
    ]);
}

it('exposes the six proxycli routes with stable methods', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn (Route $route): bool => str_starts_with((string) $route->getName(), 'proxycli:'))
        ->mapWithKeys(static fn (Route $route): array => [
            $route->getName() => [$route->uri(), $route->methods()],
        ])
        ->all();

    expect($routes)->toBe([
        'proxycli:enable' => ['api/v1/proxycli', ['POST']],
        'proxycli:disable' => ['api/v1/proxycli', ['DELETE']],
        'proxycli:status' => ['api/v1/proxycli', ['GET', 'HEAD']],
        'proxycli:list' => ['api/v1/proxycli/providers', ['GET', 'HEAD']],
        'proxycli:show' => ['api/v1/proxycli/providers/{provider}', ['GET', 'HEAD']],
        'proxycli:update' => ['api/v1/proxycli/accounts/{account}', ['PATCH']],
    ]);
});

it('requires Gateway authorization for every proxycli action', function (): void {
    $attribute = new ReflectionClass(ProxyCliController::class)
        ->getAttributes(RequiresNodeAccess::class)[0]
        ->newInstance();

    expect($attribute->servingNode)->toBe(ServingNode::Gateway);
});

it('enables proxycli when shared Valkey sits on a database Node', function (): void {
    $gateway = proxycli_gateway();
    $node = proxycli_node();
    proxycli_valkey($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/proxycli', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.hostname', 'proxycli.orbit')
        ->assertJsonPath('data.node_id', $node->id)
        ->assertJsonPath('data.cache_connection', 'valkey')
        ->assertJsonMissingPath('data.cliproxy_management_key')
        ->assertJsonMissingPath('data.read_token');

    $environment = app(RecordingProxyCliRuntimeLifecycle::class)->environment;

    expect(Process::query()->where('name', ProxyCliProcess::NAME)->exists())->toBeTrue()
        ->and(app(RecordingProxyCliRuntimeLifecycle::class)->converged)->toBeTrue()
        ->and(app(RecordingProxyCliPublicationManager::class)->converged)->toBeTrue()
        ->and($environment['PROXYCLI_CACHE_HOST'])->toBe('10.44.0.8')
        ->and($environment['PROXYCLI_CACHE_PORT'])->toBe('6379');
});

it('fails closed when the cache connection is missing, not redis, or unplaced', function (string $setup, string $code): void {
    $gateway = proxycli_gateway();
    $node = proxycli_node();

    if ($setup === 'mysql') {
        DatabaseConnection::query()->create([
            'slug' => 'valkey',
            'driver' => DatabaseDriver::Mysql,
            'node_id' => $node->id,
            'host' => '10.44.0.8',
            'port' => 3306,
            'database' => 'app',
        ]);
    }

    if ($setup === 'unplaced') {
        DatabaseConnection::query()->create([
            'slug' => 'valkey',
            'driver' => DatabaseDriver::Redis,
            'node_id' => $node->id,
            'host' => '10.44.0.8',
            'port' => 6379,
            'database' => '0',
        ]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/proxycli', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', $code);
})->with([
    'missing' => ['missing', 'proxycli.cache_missing'],
    'mysql' => ['mysql', 'proxycli.cache_invalid'],
    'unplaced' => ['unplaced', 'proxycli.cache_unplaced'],
]);

it('hides provider reads until the fleet feature is enabled', function (): void {
    $gateway = proxycli_gateway();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/proxycli/providers')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'proxycli.disabled');
});

it('reads providers from the snapshot and never calls CLIProxyAPI', function (): void {
    $gateway = proxycli_gateway();
    $node = proxycli_node();
    proxycli_valkey($node);
    Http::fake();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/proxycli', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    app(ProxyCliSnapshotStore::class)->write([
        new ProxyCliAccount('plus.json', 'codex', 'plus', false, 'enabled', [
            new ProxyCliWindow('7d', 30),
            new ProxyCliWindow('5h', 12),
        ]),
    ], '2026-09-20T12:00:00+00:00');

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/proxycli/providers')
        ->assertOk()
        ->assertJsonPath('data.0.provider', 'codex')
        ->assertJsonPath('data.0.windows.0.label', '7d')
        ->assertJsonPath('data.0.windows.1.label', '5h');

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/proxycli/providers')
        ->assertOk();

    Http::assertNothingSent();
});

it('toggles an account in CLIProxyAPI then recompiles from cache', function (): void {
    $gateway = proxycli_gateway();
    $node = proxycli_node();
    proxycli_valkey($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/proxycli', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    app(ProxyCliSnapshotStore::class)->write([
        new ProxyCliAccount('plus.json', 'codex', 'plus', false, 'enabled', [
            new ProxyCliWindow('7d', 30),
        ]),
    ], '2026-09-20T12:00:00+00:00');

    $client = new class implements ProxyCliManagementClient
    {
        public int $statusCalls = 0;

        public int $quotaCalls = 0;

        public function authFiles(string $baseUrl, string $managementKey): array
        {
            return [];
        }

        public function apiCall(string $baseUrl, string $managementKey, string $authIndex, string $url, array $headers = []): ProxyCliUsageResponse
        {
            $this->quotaCalls++;

            return new ProxyCliUsageResponse(200, []);
        }

        public function setDisabled(string $baseUrl, string $managementKey, string $account, bool $disabled): void
        {
            $this->statusCalls++;
        }
    };
    app()->instance(ProxyCliManagementClient::class, $client);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->patchJson('/api/v1/proxycli/accounts/plus.json', ['disabled' => true])
        ->assertOk()
        ->assertJsonPath('data.disabled', true);

    expect($client->statusCalls)->toBe(1)
        ->and($client->quotaCalls)->toBe(0)
        ->and(app(ProxyCliSnapshotStore::class)->accounts()[0]->disabled)->toBeTrue();
});

it('disables the extension, stops the process, and hides provider reads', function (): void {
    $gateway = proxycli_gateway();
    $node = proxycli_node();
    proxycli_valkey($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/proxycli', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->deleteJson('/api/v1/proxycli')
        ->assertOk()
        ->assertJsonPath('data.enabled', false);

    expect(Process::query()->where('name', ProxyCliProcess::NAME)->exists())->toBeFalse()
        ->and(app(RecordingProxyCliRuntimeLifecycle::class)->removed)->toBeTrue()
        ->and(app(RecordingProxyCliPublicationManager::class)->removed)->toBeTrue();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/proxycli/providers')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'proxycli.disabled');
});

it('collects once under the distributed lock', function (): void {
    $cache = app(ProxyCliCache::class);
    $first = $cache->acquire('orbit:proxycli:lock', 'collector-a', 30);
    $second = $cache->acquire('orbit:proxycli:lock', 'collector-b', 30);

    expect($first)->toBeTrue()->and($second)->toBeFalse();

    $cache->release('orbit:proxycli:lock', 'collector-a');
    expect($cache->acquire('orbit:proxycli:lock', 'collector-b', 30))->toBeTrue();
});
