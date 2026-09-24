<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\ProxyCli\ProxyCliAccount;
use App\Domain\ProxyCli\ProxyCliCache;
use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\ProxyCli\ProxyCliPublicationManager;
use App\Domain\ProxyCli\ProxyCliRuntimeLifecycle;
use App\Domain\ProxyCli\ProxyCliSnapshotStore;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\ProxyCli\ProxyCliWindow;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Api\ProxyCliController;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\ProxyCli\RecordingProxyCliPublicationManager;
use App\Infrastructure\ProxyCli\RecordingProxyCliRuntimeLifecycle;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route as OrbitRoute;
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

function proxycli_hostname_route(Node $node, string $upstream, ?int $processId = null): OrbitRoute
{
    $route = OrbitRoute::query()->create([
        'kind' => RouteKind::CustomProxy,
        'node_id' => $node->id,
        'domain' => 'collector.cli-proxy-api.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->customProxy()->create(['node_id' => $node->id, 'process_id' => $processId, 'upstream' => $upstream]);
    $route->update(['status' => RouteStatus::Active]);

    return $route;
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
        ->assertJsonPath('data.hostname', 'collector.cli-proxy-api.orbit')
        ->assertJsonPath('data.node_id', $node->id)
        ->assertJsonPath('data.cache_connection', 'valkey')
        ->assertJsonMissingPath('data.cliproxy_management_key')
        ->assertJsonMissingPath('data.read_token');

    $environment = app(RecordingProxyCliRuntimeLifecycle::class)->environment;

    $process = Process::query()->where('name', ProxyCliProcess::NAME)->first();

    expect($process)->not->toBeNull()
        ->and($process->runtime_config['command'])->toBe([
            ProxyCliProcess::EXECUTABLE,
            '/var/lib/orbit/proxycli/server.py',
        ])
        ->and($process->runtime_config['environment']['PROXYCLI_CACHE_HOST'])->toBe('10.44.0.8')
        ->and($process->runtime_config['environment']['PROXYCLI_CACHE_PORT'])->toBe('6379')
        ->and($process->runtime_config['environment']['PROXYCLI_CLIPROXY_URL'])->toBe('http://127.0.0.1:8317')
        ->and(app(RecordingProxyCliRuntimeLifecycle::class)->converged)->toBeTrue()
        ->and(app(RecordingProxyCliPublicationManager::class)->converged)->toBeTrue()
        ->and($environment['PROXYCLI_CACHE_HOST'])->toBe('10.44.0.8')
        ->and($environment['PROXYCLI_CACHE_PORT'])->toBe('6379');
});

it('keeps the collector hostname on a second enable', function (): void {
    $gateway = proxycli_gateway();
    $node = proxycli_node();
    proxycli_valkey($node);
    $payload = [
        'node_id' => $node->id,
        'cache_connection' => 'valkey',
        'cliproxy_url' => 'http://127.0.0.1:8317',
        'cliproxy_management_key' => 'management-key',
    ];

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/proxycli', $payload)
        ->assertCreated()
        ->assertJsonPath('data.hostname', 'collector.cli-proxy-api.orbit');

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/proxycli', $payload)
        ->assertCreated()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.hostname', 'collector.cli-proxy-api.orbit');
});

it('hands the collector custom proxy Route to the publication takeover', function (): void {
    $gateway = proxycli_gateway();
    $node = proxycli_node();
    proxycli_valkey($node);
    $route = proxycli_hostname_route($node, 'http://127.0.0.1:8787');

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/proxycli', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated()
        ->assertJsonPath('data.hostname', 'collector.cli-proxy-api.orbit');

    expect(app(RecordingProxyCliPublicationManager::class)->takeoverRouteId)->toBe($route->id);
});

it('takes over the Route before it restarts the collector', function (): void {
    $gateway = proxycli_gateway();
    $node = proxycli_node();
    proxycli_valkey($node);
    $route = proxycli_hostname_route($node, 'http://127.0.0.1:8787');
    $events = new ArrayObject;
    app()->instance(ProxyCliPublicationManager::class, new class($events) implements ProxyCliPublicationManager
    {
        public function __construct(private ArrayObject $events) {}

        public function converge(Node $node, int $port = ProxyCliProcess::PORT, ?OrbitRoute $takeover = null): void
        {
            $this->events->append("publication:route-{$takeover?->id}");
        }

        public function remove(Node $node): void {}
    });
    app()->instance(ProxyCliRuntimeLifecycle::class, new class($events) implements ProxyCliRuntimeLifecycle
    {
        public function __construct(private ArrayObject $events) {}

        public function converge(Node $node, array $environment, int $port = ProxyCliProcess::PORT): void
        {
            $this->events->append('runtime');
        }

        public function remove(Node $node): void {}
    });

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/proxycli', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    expect($events->getArrayCopy())->toBe(["publication:route-{$route->id}", 'runtime']);
});

it('refuses to enable while another Route holds the collector hostname', function (Closure $route): void {
    $gateway = proxycli_gateway();
    $node = proxycli_node();
    proxycli_valkey($node);
    $holder = $route($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/proxycli', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'proxycli.hostname_taken');

    expect(app(ProxyCliState::class)->enabled())->toBeFalse()
        ->and(app(RecordingProxyCliRuntimeLifecycle::class)->converged)->toBeFalse()
        ->and(app(RecordingProxyCliPublicationManager::class)->converged)->toBeFalse()
        ->and(OrbitRoute::query()->whereKey($holder->id)->exists())->toBeTrue();
})->with([
    'another Node' => [fn (Node $node): OrbitRoute => proxycli_hostname_route(proxycli_node('services', '10.44.0.17'), 'http://127.0.0.1:8787')],
    'another port' => [fn (Node $node): OrbitRoute => proxycli_hostname_route($node, 'http://127.0.0.1:8317')],
    'a Process target' => [fn (Node $node): OrbitRoute => proxycli_hostname_route($node, 'http://127.0.0.1:8787', Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => 'collector-copy',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => [
            'image' => 'collector:latest',
            'command' => ['collector'],
            'environment' => [],
            'ports' => ['127.0.0.1:8787:8787/tcp'],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ])->id)],
]);

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

it('toggles an account through the collector using its auth index', function (): void {
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
        new ProxyCliAccount('auth-index-42', 'codex', 'plus', false, 'enabled', [
            new ProxyCliWindow('7d', 30),
        ]),
    ], '2026-09-20T12:00:00+00:00');

    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldReceive('rootCertificate')->once()->andReturn('test Orbit root certificate');
    app()->instance(LeafCertificateSigner::class, $signer);
    Http::fake();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->patchJson('/api/v1/proxycli/accounts/auth-index-42', ['disabled' => true])
        ->assertOk()
        ->assertJsonPath('data.id', 'auth-index-42')
        ->assertJsonPath('data.disabled', true);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://10.44.0.8/v1/accounts/auth-index-42'
        && $request->method() === 'PATCH'
        && $request->hasHeader('Host', 'collector.cli-proxy-api.orbit')
        && $request->hasHeader('Authorization', 'Bearer '.app(ProxyCliState::class)->controlToken())
        && $request['disabled'] === true);

    expect(app(ProxyCliSnapshotStore::class)->accounts()[0]->disabled)->toBeTrue();
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

it('republishes private DNS without the collector name when the extension is disabled', function (): void {
    $gateway = proxycli_gateway();
    $node = proxycli_node();
    proxycli_valkey($node);
    $publication = new class implements ProxyCliPublicationManager
    {
        public ?string $dnsAtRemoval = null;

        public function converge(Node $node, int $port = ProxyCliProcess::PORT, ?OrbitRoute $takeover = null): void {}

        public function remove(Node $node): void
        {
            $this->dnsAtRemoval = app(AppDevDnsConfigRenderer::class)->render();
        }
    };
    app()->instance(ProxyCliPublicationManager::class, $publication);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/proxycli', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    expect(app(AppDevDnsConfigRenderer::class)->render())->toContain('host-record=collector.cli-proxy-api.orbit,10.44.0.8');

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->deleteJson('/api/v1/proxycli')
        ->assertOk();

    expect($publication->dnsAtRemoval)->toBeString()
        ->not->toContain('collector.cli-proxy-api.orbit');
});

it('collects once under the distributed lock', function (): void {
    $cache = app(ProxyCliCache::class);
    $first = $cache->acquire('orbit:proxycli:lock', 'collector-a', 30);
    $second = $cache->acquire('orbit:proxycli:lock', 'collector-b', 30);

    expect($first)->toBeTrue()->and($second)->toBeFalse();

    $cache->release('orbit:proxycli:lock', 'collector-a');
    expect($cache->acquire('orbit:proxycli:lock', 'collector-b', 30))->toBeTrue();
});
