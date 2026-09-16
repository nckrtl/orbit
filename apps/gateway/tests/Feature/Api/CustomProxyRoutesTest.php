<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeAction;
use App\Actions\Processes\RemoveProcessAction;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Routes\CustomProxyRouteProjector;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use App\Models\RouteCustomProxy;
use App\Models\RouteTarget;
use Tests\Support\FakeCustomProxyRouteProjector;
use Tests\Support\FakeRouteRemovalProjector;

beforeEach(function (): void {
    $this->projector = new FakeCustomProxyRouteProjector;
    $this->removal = new FakeRouteRemovalProjector;
    app()->instance(CustomProxyRouteProjector::class, $this->projector);
    app()->instance(RouteRemovalProjector::class, $this->removal);

    $this->gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);

    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->node = custom_proxy_node('beast', '10.44.0.7');
    $this->target = AppInstance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'checkout_path' => '/srv/orbit/apps/acme/main',
        'status' => AppInstanceState::Active,
    ]);
});

describe('custom proxy Route create', function (): void {
    it('creates a Node-owned custom proxy from an upstream URL', function (string $domain): void {
        $created = $this
            ->postJson('/api/v1/routes', [
                'domain' => $domain,
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'custom_proxy')
            ->assertJsonPath('data.app_id', null)
            ->assertJsonPath('data.node_id', $this->node->id)
            ->assertJsonPath('data.cluster_id', null)
            ->assertJsonPath('data.domain', $domain)
            ->assertJsonPath('data.publication', 'private')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.target', null)
            ->assertJsonPath('data.targets', [])
            ->assertJsonPath('data.process_id', null)
            ->assertJsonPath('data.upstream', 'http://127.0.0.1:4788');

        $routeId = $created->json('data.id');

        expect(Route::query()->findOrFail($routeId)->kind)
            ->toBe(RouteKind::CustomProxy)
            ->and(RouteCustomProxy::query()->where('route_id', $routeId)->sole()->upstream)
            ->toBe('http://127.0.0.1:4788')
            ->and(RouteTarget::query()->where('route_id', $routeId)->count())
            ->toBe(0)
            ->and($this->projector->routeIds)
            ->toBe([$routeId]);
    })->with([
        'executor.orbit',
        'grafana.internal',
        'foo.bar',
        'something.test',
    ]);

    it('creates a custom proxy from a Node Process listener', function (): void {
        $process = custom_proxy_process($this->node, 'executor', ['127.0.0.1:4788:80/tcp']);

        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'process_id' => $process->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'custom_proxy')
            ->assertJsonPath('data.process_id', $process->id)
            ->assertJsonPath('data.upstream', 'http://127.0.0.1:4788');
    });
});

describe('custom proxy Route refusals', function (): void {
    it('refuses reserved platform hostnames', function (string $domain): void {
        $this
            ->postJson('/api/v1/routes', [
                'domain' => $domain,
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.domain_conflict');

        expect(Route::query()->count())->toBe(0);
    })->with([
        'gateway.orbit',
        'metrics.orbit',
    ]);

    it('refuses stealing an App Route domain', function (): void {
        $this
            ->postJson('/api/v1/routes', [
                'app_id' => $this->orbitApp->id,
                'domain' => 'executor.orbit',
                'publication' => 'private',
                'app_instance_id' => $this->target->id,
            ])
            ->assertCreated();

        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.domain_conflict');

        expect(Route::query()->where('kind', RouteKind::CustomProxy)->count())->toBe(0);
    });

    it('refuses a remote or non-loopback upstream', function (string $upstream): void {
        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => $upstream,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'route.upstream_invalid');
    })->with([
        'remote' => ['http://10.44.0.8:4788'],
        'https' => ['https://127.0.0.1:4788'],
        'path' => ['http://127.0.0.1:4788/status'],
    ]);

    it('refuses mixing an App owner with a custom proxy upstream', function (): void {
        $this
            ->postJson('/api/v1/routes', [
                'app_id' => $this->orbitApp->id,
                'domain' => 'executor.orbit',
                'publication' => 'private',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertUnprocessable();

        expect(Route::query()->count())->toBe(0);
    });

    it('returns the existing Route on an identical custom proxy retry', function (): void {
        $first = $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertCreated();

        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        expect(Route::query()->count())->toBe(1);
    });

    it('refuses a conflicting custom proxy retry', function (): void {
        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertCreated();

        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:9000',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.retry_conflict');

        expect(RouteCustomProxy::query()->sole()->upstream)->toBe('http://127.0.0.1:4788');
    });
});

describe('custom proxy Route list, show, destroy, and App mutations', function (): void {
    it('lists and shows kind and upstream instead of App targets', function (): void {
        $created = $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertCreated();
        $routeId = $created->json('data.id');

        $this
            ->getJson('/api/v1/routes')
            ->assertOk()
            ->assertJsonPath('data.0.id', $routeId)
            ->assertJsonPath('data.0.kind', 'custom_proxy')
            ->assertJsonPath('data.0.upstream', 'http://127.0.0.1:4788')
            ->assertJsonPath('data.0.targets', []);

        $this
            ->getJson("/api/v1/routes/{$routeId}")
            ->assertOk()
            ->assertJsonPath('data.kind', 'custom_proxy')
            ->assertJsonPath('data.upstream', 'http://127.0.0.1:4788')
            ->assertJsonPath('data.process_id', null);
    });

    it('destroys a custom proxy without touching an App Route', function (): void {
        $appRoute = $this
            ->postJson('/api/v1/routes', [
                'app_id' => $this->orbitApp->id,
                'domain' => 'app.example.test',
                'publication' => 'private',
                'app_instance_id' => $this->target->id,
            ])
            ->assertCreated()
            ->json('data.id');
        $proxyRoute = $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/v1/routes/{$proxyRoute}")->assertOk();

        expect(Route::query()->whereKey($proxyRoute)->exists())
            ->toBeFalse()
            ->and(RouteCustomProxy::query()->count())
            ->toBe(0)
            ->and(Route::query()->whereKey($appRoute)->exists())
            ->toBeTrue()
            ->and(RouteTarget::query()->where('route_id', $appRoute)->count())
            ->toBe(1);
    });

    it('refuses Set, Update, and Clear on a custom proxy Route', function (): void {
        $routeId = $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertCreated()
            ->json('data.id');

        $this
            ->putJson("/api/v1/routes/{$routeId}/target", ['app_instance_id' => $this->target->id])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.kind_unsupported');
        $this
            ->patchJson("/api/v1/routes/{$routeId}", ['domain' => 'other.orbit'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.kind_unsupported');
        $this
            ->deleteJson("/api/v1/routes/{$routeId}/target")
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.kind_unsupported');

        expect(Route::query()->findOrFail($routeId)->domain)
            ->toBe('executor.orbit')
            ->and(RouteTarget::query()->where('route_id', $routeId)->count())
            ->toBe(0);
    });
});

describe('custom proxy ownership guards', function (): void {
    it('refuses Process removal while a custom proxy targets the Process', function (): void {
        $process = custom_proxy_process($this->node, 'executor', ['127.0.0.1:4788:80/tcp']);
        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'process_id' => $process->id,
            ])
            ->assertCreated();

        $runtime = Mockery::mock(ProcessRuntimeManager::class);
        $runtime->shouldNotReceive('remove');

        expect(fn () => new RemoveProcessAction($runtime, new ProcessTargetResolver)->execute($process))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('process.has_routes');
            })
            ->and($process->fresh())
            ->not->toBeNull();
    });

    it('refuses Node removal while a custom proxy references the Node', function (): void {
        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertCreated();

        expect(fn () => app(RouteRemovalGuard::class)->assertNodeRemovable($this->node))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('route.reconciliation_required');
            })
            ->and(fn () => app(RemoveNodeAction::class)->execute($this->node, $this->gateway, offline: true, force: true))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('route.reconciliation_required');
            })
            ->and($this->node->fresh())
            ->not->toBeNull();
    });
});

describe('custom proxy AppDev sites', function (): void {
    it('emits a Node-direct local HTTP site even when the serving Node is in a Cluster', function (): void {
        $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
        $router = custom_proxy_node('edge-router', '10.45.0.10');
        $router->update(['cluster_id' => $cluster->id]);
        $router->roles()->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
        $this->node->update(['cluster_id' => $cluster->id]);

        $routeId = $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertCreated()
            ->json('data.id');

        $sites = new AppDevSiteRepository;
        $onNode = $sites->forNode($this->node->refresh());
        $onRouter = $sites->forNode($router->refresh());

        expect($onNode)
            ->toHaveCount(1)
            ->and($onNode->first()?->domain)
            ->toBe('executor.orbit')
            ->and($onNode->first()?->nodeId)
            ->toBe($this->node->id)
            ->and($onNode->first()?->localHttpUpstream)
            ->toBe('127.0.0.1:4788')
            ->and($onNode->first()?->isLocalHttpProxy())
            ->toBeTrue()
            ->and($onNode->first()?->scope)
            ->toBe("route-{$routeId}")
            ->and($onRouter)
            ->toHaveCount(0);
    });
});

function custom_proxy_node(string $name, string $wireguardIp): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.'.substr($wireguardIp, strrpos($wireguardIp, '.') + 1),
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
}

function custom_proxy_process(Node $node, string $name, array $ports): Process
{
    return Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => $name,
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => [
            'image' => 'executor:latest',
            'command' => ['executor'],
            'environment' => [],
            'ports' => $ports,
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
}
