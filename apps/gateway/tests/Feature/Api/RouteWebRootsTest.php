<?php

declare(strict_types=1);

use App\Actions\Instances\RemoveInstanceAction;
use App\Actions\Routes\SynchronizeRouteWebRootUrlsAction;
use App\Domain\AppDev\AppDevPhpFpmManager;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionCloneRouteProjector;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Instances\ProductionWebRootManager;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalException;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Instances\Removal\ProductionInstanceContentRetention;
use App\Domain\Instances\RouteApplicationUrlWriter;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteRemovalStep;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Doctor\ProductionInstanceInspectionExpectationFactory;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Projects\NativeProjectUpdateProjectionMutator;
use App\Infrastructure\Routes\NativeRouteRemovalProjector;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeRouteRemovalProjector;

beforeEach(function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->node = web_root_node('dev-web', '10.44.0.20', production: false);
    $this->instance = web_root_instance($this->project, $this->node);
    $this->urls = new class implements RouteApplicationUrlWriter
    {
        /** @var list<array{string, string}> */
        public array $writes = [];

        public function configureDirectoryUrl(Instance $instance, string $relativeDirectory, string $url): void
        {
            $this->writes[] = [$relativeDirectory, $url];
        }
    };
    app()->instance(RouteApplicationUrlWriter::class, $this->urls);
});

describe('route web root', function (): void {
    it('refuses an invalid web root on create and update', function (): void {
        $own = web_root_route($this->instance, 'acme.web.test');

        foreach (['../docs/public', '/srv/docs/public', '.', 'apps//docs/public', 'apps/docs/public/', 'apps/./public'] as $webRoot) {
            $this->postJson('/api/v1/routes', ['instance_id' => $this->instance->id, 'domain' => 'docs.web.test', 'web_root' => $webRoot])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation.failed')
                ->assertJsonPath('error.details.web_root.0', fn (string $message): bool => str_contains($message, 'normalized relative path'));
            $this->patchJson("/api/v1/routes/{$own->id}", ['web_root' => $webRoot])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation.failed');
        }

        $this->postJson('/api/v1/routes', ['domain' => 'tool.web.test', 'node_id' => $this->node->id, 'upstream' => 'http://127.0.0.1:4000', 'web_root' => 'public'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.web_root.0', 'A custom proxy Route has no web root.');
        $this->patchJson("/api/v1/routes/{$own->id}", ['web_root' => 'apps/docs/public', 'publication' => 'private'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'route.web_root_update_separate');
        expect(Route::query()->count())->toBe(1)->and($own->refresh()->web_root)->toBeNull();
    });

    it('creates a Route with a web root next to the Instance Route and lists both on the Instance', function (): void {
        $own = web_root_route($this->instance, 'acme.web.test');
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldReceive('converge')->once();
        app()->instance(DevelopmentRouteProjector::class, $projector);
        $payload = ['instance_id' => $this->instance->id, 'domain' => 'docs.acme.web.test', 'web_root' => 'apps/docs/public'];

        $created = $this->postJson('/api/v1/routes', $payload)
            ->assertCreated()
            ->assertJsonPath('data.web_root', 'apps/docs/public')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.target.instance_id', $this->instance->id);

        expect($this->urls->writes)->toBe([['apps/docs', 'https://docs.acme.web.test']]);
        $this->getJson("/api/v1/instances/{$this->instance->id}")
            ->assertOk()
            ->assertJsonPath('data.route.id', $own->id)
            ->assertJsonPath('data.domain', 'acme.web.test')
            ->assertJsonPath('data.routes.0.web_root', null)
            ->assertJsonPath('data.routes.1.id', $created->json('data.id'))
            ->assertJsonPath('data.routes.1.web_root', 'apps/docs/public');
        $this->postJson('/api/v1/routes', $payload)->assertOk()->assertJsonPath('data.id', $created->json('data.id'));
        $this->postJson('/api/v1/routes', [...$payload, 'web_root' => 'apps/admin/public'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.retry_conflict');
        $this->postJson('/api/v1/routes', ['instance_id' => $this->instance->id, 'domain' => 'second.acme.web.test'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.target_conflict');
    });

    it('serves each web root from its own Caddy site, leaf, and PHP-FPM pool', function (): void {
        $own = web_root_route($this->instance, 'acme.web.test');
        $docs = web_root_route($this->instance, 'docs.acme.web.test', 'apps/docs/public');
        $alias = web_root_route($this->instance, 'alias.acme.web.test', 'public');
        $sites = web_root_sites($this->node);
        $checkout = $this->instance->checkout_path;
        $suffix = substr(hash('sha256', 'apps/docs'), 0, 8);

        expect($sites->get('acme.web.test')->poolName())->toBe("orbit-app-instance-{$this->instance->id}")
            ->and($sites->get('acme.web.test')->certificateDirectory())->toBe("/etc/caddy/orbit-certificates/app-instance-{$this->instance->id}/current")
            ->and($sites->get('docs.acme.web.test')->documentRoot)->toBe('apps/docs/public')
            ->and($sites->get('docs.acme.web.test')->poolName())->toBe("orbit-app-instance-{$this->instance->id}-{$suffix}")
            ->and($sites->get('docs.acme.web.test')->certificateDirectory())->toBe("/etc/caddy/orbit-certificates/route-{$docs->id}/current")
            ->and($sites->get('alias.acme.web.test')->poolName())->toBe("orbit-app-instance-{$this->instance->id}")
            ->and($sites->get('alias.acme.web.test')->certificateDirectory())->toBe("/etc/caddy/orbit-certificates/route-{$alias->id}/current");

        $caddy = new DevelopmentCaddyConfigRenderer()->render(collect([$sites->get('docs.acme.web.test')]));
        expect($caddy)->toContain("root * {$checkout}/apps/docs/public")
            ->toContain("unix//run/php/orbit-app-instance-{$this->instance->id}-{$suffix}.sock")
            ->toContain("/etc/caddy/orbit-certificates/route-{$docs->id}/current/cert.pem");

        $pools = new DevelopmentPhpFpmConfigRenderer()->render($sites->values(), new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
        expect(substr_count($pools, '[orbit-app-instance-'))->toBe(2)
            ->and($pools)->toContain("[orbit-app-instance-{$this->instance->id}]\n")
            ->toContain("chdir = {$checkout}\n")
            ->toContain("[orbit-app-instance-{$this->instance->id}-{$suffix}]\n")
            ->toContain("chdir = {$checkout}/apps/docs\n");
        expect($own->refresh()->web_root)->toBeNull();
    });

    it('gives APP_URL to the Instance Route, then to the oldest Route serving a directory', function (): void {
        web_root_route($this->instance, 'acme.web.test');
        $docs = web_root_route($this->instance, 'docs.acme.web.test', 'apps/docs/public');
        web_root_route($this->instance, 'docs-two.acme.web.test', 'apps/docs/public');
        $alias = web_root_route($this->instance, 'alias.acme.web.test', 'public');
        $admin = web_root_route($this->instance, 'admin.acme.web.test', 'apps/admin/public');
        $winners = static fn (Instance $instance): array => array_map(
            static fn (Route $route): string => $route->domain,
            RouteWebRoot::applicationUrlRoutes($instance->refresh(), Route::query()->get()),
        );

        expect($winners($this->instance))->toBe(['apps/docs' => $docs->domain, 'apps/admin' => $admin->domain]);

        app(SynchronizeRouteWebRootUrlsAction::class)->execute($this->instance);
        expect($this->urls->writes)->toBe([['apps/docs', 'https://docs.acme.web.test'], ['apps/admin', 'https://admin.acme.web.test']]);

        $this->instance->update(['root' => 'apps/docs/public']);
        expect($winners($this->instance))->toBe(['' => $alias->domain, 'apps/admin' => $admin->domain]);
    });

    it('removes a Route with a web root from an active Instance and retires its pool', function (): void {
        $removal = new FakeRouteRemovalProjector;
        app()->instance(RouteRemovalProjector::class, $removal);
        $own = web_root_route($this->instance, 'acme.web.test');
        $first = web_root_route($this->instance, 'docs.acme.web.test', 'apps/docs/public');
        $second = web_root_route($this->instance, 'docs-two.acme.web.test', 'apps/docs/public');

        $this->deleteJson("/api/v1/routes/{$first->id}")->assertOk();
        expect($this->urls->writes)->toBe([['apps/docs', 'https://docs-two.acme.web.test']])
            ->and($removal->events)->toContain('php');

        $this->deleteJson("/api/v1/routes/{$second->id}")->assertOk();
        $pools = new DevelopmentPhpFpmConfigRenderer()->render(web_root_sites($this->node)->values(), new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
        expect(Route::query()->pluck('id')->all())->toBe([$own->id])
            ->and($this->instance->refresh()->status)->toBe(InstanceState::Active)
            ->and(substr_count($pools, '[orbit-app-instance-'))->toBe(1)
            ->and($pools)->not->toContain('apps/docs');
        $this->deleteJson("/api/v1/routes/{$own->id}")->assertConflict()->assertJsonPath('error.code', 'route.target_conflict');
    });

    it('changes a web root through route update and keeps the Instance Route', function (): void {
        $own = web_root_route($this->instance, 'acme.web.test');
        $docs = web_root_route($this->instance, 'docs.acme.web.test', 'apps/docs/public');
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldReceive('converge')->once();
        app()->instance(DevelopmentRouteProjector::class, $projector);

        $this->patchJson("/api/v1/routes/{$docs->id}", ['web_root' => 'apps/admin/public'])
            ->assertOk()
            ->assertJsonPath('data.web_root', 'apps/admin/public');
        expect($this->urls->writes)->toBe([['apps/admin', 'https://docs.acme.web.test']]);

        $this->patchJson("/api/v1/routes/{$docs->id}", ['web_root' => null])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.target_conflict');
        $this->patchJson("/api/v1/routes/{$own->id}", ['web_root' => 'apps/docs/public'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.web_root_conflict');
        $this->patchJson("/api/v1/routes/{$docs->id}", ['domain' => 'other.acme.web.test'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.web_root_domain_immutable');
        expect($docs->refresh()->web_root)->toBe('apps/admin/public')
            ->and($own->refresh()->web_root)->toBeNull();
    });

    it('keeps the Routes with a web root when it refuses an Instance removal', function (): void {
        $removal = new FakeRouteRemovalProjector;
        app()->instance(RouteRemovalProjector::class, $removal);
        $own = web_root_route($this->instance, 'acme.web.test');
        $docs = web_root_route($this->instance, 'docs.acme.web.test', 'apps/docs/public');
        $source = Mockery::mock(DevelopmentInstanceSourceRemoval::class);
        $source->shouldReceive('inspect')->once()->andThrow(new ResourceOperationException('instance.remove_refused', 'The checkout has uncommitted changes.', 409));
        app()->instance(DevelopmentInstanceSourceRemoval::class, $source);

        expect(fn () => app(RemoveInstanceAction::class)->execute($this->instance, force: false))
            ->toThrow(ResourceOperationException::class, 'The checkout has uncommitted changes.');
        expect(Route::query()->orderBy('id')->pluck('id')->all())->toBe([$own->id, $docs->id])
            ->and($removal->routeIds)->toBe([])
            ->and(InstanceRemoval::query()->count())->toBe(0)
            ->and($this->instance->refresh()->status)->toBe(InstanceState::Active);
    });

    it('removes the Routes with a web root once it accepts an Instance removal', function (): void {
        $removal = new FakeRouteRemovalProjector;
        app()->instance(RouteRemovalProjector::class, $removal);
        $own = web_root_route($this->instance, 'acme.web.test');
        $docs = web_root_route($this->instance, 'docs.acme.web.test', 'apps/docs/public');
        $checkout = $this->instance->checkout_path;
        $source = Mockery::mock(DevelopmentInstanceSourceRemoval::class);
        $source->shouldReceive('inspect')->andReturn(new InstanceSourceInventory(
            instanceId: $this->instance->id,
            layout: 'checkout',
            repositoryIdentity: $this->project->repository_identity,
            checkoutPath: $checkout,
            root: '/srv/orbit/apps',
            branch: 'main',
            startingCommit: str_repeat('a', 40),
            commonRepositoryPath: $checkout,
            sourceIdentity: 'web-root-source',
            linkedWorktreePaths: [$checkout],
            digest: hash('sha256', 'web-root-source'),
        ));
        app()->instance(DevelopmentInstanceSourceRemoval::class, $source);
        $finalizer = Mockery::mock(DevelopmentInstanceSourceFinalizer::class);
        $finalizer->shouldReceive('prepare', 'revalidate', 'inspectRecorded')->andReturnUsing(static function () use ($removal, $docs): InstanceSourceRevalidationState {
            expect($removal->routeIds)->toContain($docs->id);

            throw new ResourceOperationException('instance.test_stop', 'Stopped after acceptance.', 409);
        });
        app()->instance(DevelopmentInstanceSourceFinalizer::class, $finalizer);

        expect(fn () => app(RemoveInstanceAction::class)->execute($this->instance, force: false, runTeardown: false))
            ->toThrow(InstanceRemovalException::class, 'Instance removal was accepted but remains incomplete.');
        expect(Route::query()->find($docs->id))->toBeNull()
            ->and(Route::query()->find($own->id))->not->toBeNull()
            ->and(InstanceRemoval::query()->count())->toBe(1);
    });

    it('moves APP_URL to the new winner when the Project root changes', function (): void {
        web_root_route($this->instance, 'acme.web.test');
        web_root_route($this->instance, 'docs.acme.web.test', 'apps/docs/public');
        web_root_route($this->instance, 'alias.acme.web.test', 'public');
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldReceive('converge')->once();
        app()->instance(DevelopmentRouteProjector::class, $projector);
        $this->project->update(['root' => 'apps/docs/public']);

        app(NativeProjectUpdateProjectionMutator::class)->publishRoot($this->project, 'apps/docs/public', [
            'instances' => [['instance_id' => $this->instance->id]],
        ]);

        expect($this->urls->writes)->toBe([['', 'https://alias.acme.web.test']]);
    });

    it('creates a Route with a web root for a production Instance and prepares its release first', function (): void {
        [$instance, $events] = web_root_production($this->project);
        web_root_route($instance, 'acme.example.com');

        $this->postJson('/api/v1/routes', ['instance_id' => $instance->id, 'domain' => 'docs.example.com', 'web_root' => 'apps/docs/public'])
            ->assertCreated()
            ->assertJsonPath('data.web_root', 'apps/docs/public')
            ->assertJsonPath('data.status', 'active');

        expect($events->all)->toBe(['certificate', 'web-roots', 'runtime', 'firewall', 'workload-caddy', 'router-certificate', 'route-firewall', 'verify-workload', 'router-caddy', 'dns'])
            ->and($this->urls->writes)->toBe([['apps/docs', 'https://docs.example.com']]);
    });

    it('changes the web root of a production Route and keeps the Instance Route', function (): void {
        [$instance, $events] = web_root_production($this->project);
        $own = web_root_route($instance, 'acme.example.com');
        $docs = web_root_route($instance, 'docs.example.com', 'apps/docs/public');

        $this->patchJson("/api/v1/routes/{$docs->id}", ['web_root' => 'apps/admin/public'])
            ->assertOk()
            ->assertJsonPath('data.web_root', 'apps/admin/public');
        expect($events->all)->toBe(['certificate', 'web-roots', 'runtime', 'workload-caddy'])
            ->and($this->urls->writes)->toBe([['apps/admin', 'https://docs.example.com']]);

        $this->patchJson("/api/v1/routes/{$own->id}", ['web_root' => 'apps/docs/public'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.web_root_conflict');
        expect($own->refresh()->web_root)->toBeNull();
    });

    it('refuses to move a Route with a web root to a production Instance', function (): void {
        [$instance] = web_root_production($this->project);

        expect(fn () => RouteWebRoot::assertRetargetable($instance))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.web_root_unsupported'));
        RouteWebRoot::assertRetargetable($this->instance);
    });

    it('serves a production web root from its own pool and socket in the selected release', function (): void {
        [$instance] = web_root_production($this->project);
        web_root_route($instance, 'acme.example.com');
        web_root_route($instance, 'alias.example.com', 'public');
        web_root_route($instance, 'docs.example.com', 'apps/docs/public');
        web_root_route($instance, 'docs-raw.example.com', 'apps/docs');
        $suffix = substr(hash('sha256', 'apps/docs'), 0, 8);
        $sites = web_root_sites($instance->node);

        expect(RouteWebRoot::servedApplications($instance))->toBe([
            ['web_root' => 'apps/docs', 'directory' => 'apps/docs', 'suffix' => $suffix],
            ['web_root' => 'apps/docs/public', 'directory' => 'apps/docs', 'suffix' => $suffix],
        ])
            ->and($sites->get('acme.example.com')->socketPath())->toBe('/run/php/orbit-acme.sock')
            ->and($sites->get('alias.example.com')->socketPath())->toBe('/run/php/orbit-acme.sock')
            ->and($sites->get('docs.example.com')->socketPath())->toBe("/run/php/orbit-acme.{$suffix}.sock");
        expect(new DevelopmentCaddyConfigRenderer()->render(collect([$sites->get('docs.example.com')])))
            ->toContain('root * /home/orbit-acme/current/apps/docs/public')
            ->toContain("unix//run/php/orbit-acme.{$suffix}.sock");

        $identity = ProductionPhpRuntimeIdentity::from($instance);
        $renderer = new ProductionPhpRuntimeConfigRenderer;
        $pool = $renderer->render($identity, applications: RouteWebRoot::servedApplications($instance))->pool;
        $initial = $renderer->render($identity, initialRelease: true, applications: RouteWebRoot::servedApplications($instance))->pool;

        expect(substr_count($pool, "\n[orbit-"))->toBe(1)
            ->and($pool)->toStartWith($renderer->render($identity)->pool)
            ->toContain("[orbit-orbit-acme-{$suffix}]\n")
            ->toContain("listen = /run/php/orbit-acme.{$suffix}.sock\n")
            ->toContain("chdir = /home/orbit-acme/current/apps/docs\n")
            ->toContain("pm = ondemand\n")
            ->and($initial)->toContain("chdir = /home/orbit-acme/releases/initial/apps/docs\n")
            ->and(app(ProductionInstanceInspectionExpectationFactory::class)->make($instance)->runtimeConfiguration?->pool)->toBe($pool);
    });

    it('withdraws the pool of a production Route with a web root when the Route leaves', function (): void {
        [$instance] = web_root_production($this->project);
        $own = web_root_route($instance, 'acme.example.com');
        $docs = web_root_route($instance, 'docs.example.com', 'apps/docs/public');
        $runtime = Mockery::mock(ProductionPhpRuntimeManager::class);
        $runtime->shouldReceive('converge')->once()->withArgs(static fn (Instance $converged): bool => $converged->is($instance));
        $projector = app()->make(NativeRouteRemovalProjector::class, ['php' => Mockery::mock(AppDevPhpFpmManager::class), 'productionPhp' => $runtime]);

        expect(collect($projector->nodes($docs))->sole()->steps)->toContain(RouteRemovalStep::Php)
            ->and(collect($projector->nodes($own))->sole()->steps)->not->toContain(RouteRemovalStep::Php);
        $docs->update(['status' => 'retiring', 'sites_published' => false]);
        expect(RouteWebRoot::servedApplications($instance))->toBe([]);
        $projector->cleanupPhp($docs);
        $projector->cleanupPhp($own);
    });

    it('keeps the Routes with a web root when it refuses a production Instance removal', function (): void {
        [$instance] = web_root_production($this->project);
        $own = web_root_route($instance, 'acme.example.com');
        $docs = web_root_route($instance, 'docs.example.com', 'apps/docs/public');
        $removal = new FakeRouteRemovalProjector;
        app()->instance(RouteRemovalProjector::class, $removal);
        $content = Mockery::mock(ProductionInstanceContentRetention::class);
        $content->shouldReceive('inventory')->once()->andThrow(new ResourceOperationException('instance.remove_refused', 'The production home changed.', 409));
        app()->instance(ProductionInstanceContentRetention::class, $content);

        expect(fn () => app(RemoveInstanceAction::class)->execute($instance, force: false))
            ->toThrow(ResourceOperationException::class, 'The production home changed.');
        expect(Route::query()->orderBy('id')->pluck('id')->all())->toBe([$own->id, $docs->id])
            ->and($removal->routeIds)->toBe([])
            ->and($instance->refresh()->status)->toBe(InstanceState::Active);
    });

    it('removes the Routes with a web root once it accepts a production Instance removal', function (): void {
        [$instance] = web_root_production($this->project);
        $own = web_root_route($instance, 'acme.example.com');
        $docs = web_root_route($instance, 'docs.example.com', 'apps/docs/public');
        $removal = new FakeRouteRemovalProjector;
        app()->instance(RouteRemovalProjector::class, $removal);
        $content = Mockery::mock(ProductionInstanceContentRetention::class);
        $content->shouldReceive('inventory')->andReturn(new InstanceSourceInventory(
            instanceId: $instance->id,
            layout: $instance->source_layout,
            repositoryIdentity: $this->project->repository_identity,
            checkoutPath: $instance->checkout_path,
            root: 'public',
            branch: 'main',
            startingCommit: str_repeat('a', 40),
            commonRepositoryPath: $instance->checkout_path,
            sourceIdentity: "production:{$instance->id}:{$instance->node_id}",
            linkedWorktreePaths: [],
            digest: hash('sha256', 'production-web-root'),
        ));
        $content->shouldReceive('prepare')->andReturnUsing(static function () use ($removal, $docs): void {
            expect($removal->routeIds)->toContain($docs->id);

            throw new ResourceOperationException('instance.test_stop', 'Stopped after acceptance.', 409);
        });
        app()->instance(ProductionInstanceContentRetention::class, $content);

        expect(fn () => app(RemoveInstanceAction::class)->execute($instance, force: false))
            ->toThrow(InstanceRemovalException::class);
        expect(Route::query()->find($docs->id))->toBeNull()
            ->and(Route::query()->find($own->id))->not->toBeNull()
            ->and(InstanceRemoval::query()->count())->toBe(1);
    });

    it('keeps an existing Instance unchanged after the migration', function (): void {
        $migration = require database_path('migrations/2026_10_22_000000_add_web_root_to_routes.php');
        $migration->down();
        $route = web_root_route($this->instance, 'acme.web.test');
        $render = fn (): array => [
            new DevelopmentCaddyConfigRenderer()->render(new DevelopmentSiteRepository()->forNode($this->node)),
            new DevelopmentPhpFpmConfigRenderer()->render(web_root_sites($this->node)->values(), new ManagedUserAccount('orbit', 'orbit', '/home/orbit')),
        ];
        $before = $render();

        $migration->up();

        expect($route->refresh()->web_root)->toBeNull()
            ->and($render())->toBe($before)
            ->and($before[1])->toContain("[orbit-app-instance-{$this->instance->id}]\n")
            ->and($this->instance->refresh()->authoritativeRoute()?->id)->toBe($route->id);
        expect(fn () => web_root_route($this->instance, 'second.acme.web.test'))->toThrow(QueryException::class);
    });

    it('leaves the schema unchanged when a trigger does not match, and can run again', function (): void {
        $migration = require database_path('migrations/2026_10_22_000000_add_web_root_to_routes.php');
        $migration->down();
        $original = DB::table('sqlite_master')->where('name', 'instances_active_route_update')->value('sql');
        $changed = str_replace("IN ('active', 'activating')", "IN ('activating', 'active')", $original);
        DB::statement('DROP TRIGGER instances_active_route_update');
        DB::statement($changed);

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'The instances_active_route_update trigger is incompatible.');
        expect(Schema::hasColumn('routes', 'web_root'))->toBeFalse()
            ->and(DB::table('sqlite_master')->where('name', 'route_targets_contract_insert')->value('sql'))->not->toContain('web_root');

        DB::statement('DROP TRIGGER instances_active_route_update');
        DB::statement($original);
        $migration->up();

        expect(Schema::hasColumn('routes', 'web_root'))->toBeTrue()
            ->and(DB::table('sqlite_master')->where('name', 'instances_active_route_update')->value('sql'))->toContain('routes.web_root IS NULL');
    });

    it('refuses to roll back while a Route has a web root', function (): void {
        web_root_route($this->instance, 'acme.web.test');
        web_root_route($this->instance, 'docs.acme.web.test', 'apps/docs/public');
        $migration = require database_path('migrations/2026_10_22_000000_add_web_root_to_routes.php');

        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Routes with a web root exist.');
        expect(Schema::hasColumn('routes', 'web_root'))->toBeTrue();
    });
});

function web_root_node(string $name, string $address, bool $production): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => "{$name}.test",
        'public_ssh_host' => '192.0.2.'.substr($address, strrpos($address, '.') + 1),
        'wireguard_ip' => $address,
        'user' => 'orbit',
    ]);
    orbit_test_set_app_placement_role($node, $production);

    return $node;
}

function web_root_instance(Project $project, Node $node): Instance
{
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $node->name,
        'checkout_path' => "/srv/orbit/apps/{$project->slug}/{$node->name}",
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'status' => InstanceState::Active,
    ]);
}

/**
 * A production Instance whose release projection records each step instead of reaching the Node.
 *
 * @return array{Instance, object{all: list<string>}}
 */
function web_root_production(Project $project): array
{
    $node = web_root_node('prod-web', '10.44.0.30', production: true);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'source_layout' => 'checkout',
        'checkout_path' => '/home/orbit-acme/releases/initial',
        'production_user' => 'orbit-acme',
        'production_home' => '/home/orbit-acme',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.4',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance->refresh(), '8.4')->attributes());
    $events = new class
    {
        /** @var list<string> */
        public array $all = [];
    };
    $projection = new class($events) implements ProductionCloneRouteProjector, ProductionRouteProjector
    {
        public function __construct(private readonly object $events) {}

        public function prepareRuntime(Instance $instance, Route $route): void
        {
            $this->events->all[] = 'runtime';
        }

        public function prepareCertificate(Instance $instance, Route $route): void
        {
            $this->events->all[] = 'certificate';
        }

        public function prepareFirewall(Instance $instance): void
        {
            $this->events->all[] = 'firewall';
        }

        public function publish(Instance $instance, Route $route): void
        {
            $this->events->all[] = 'publish';
        }

        public function prepareWorkloadCaddy(Instance $instance, Route $route): void
        {
            $route->publishSites();
            $this->events->all[] = 'workload-caddy';
        }

        public function prepareRouterCertificate(Instance $instance, Route $route): void
        {
            $this->events->all[] = 'router-certificate';
        }

        public function prepareRouteFirewall(Instance $instance, Route $route): void
        {
            $this->events->all[] = 'route-firewall';
        }

        public function verifyWorkload(Instance $instance, Route $route): void
        {
            $this->events->all[] = 'verify-workload';
        }

        public function prepareRouterCaddy(Instance $instance, Route $route): void
        {
            $this->events->all[] = 'router-caddy';
        }

        public function prepareDns(Route $route): void
        {
            $this->events->all[] = 'dns';
        }
    };
    app()->instance(ProductionRouteProjector::class, $projection);
    app()->instance(ProductionCloneRouteProjector::class, $projection);
    app()->instance(ProductionWebRootManager::class, new class($events) implements ProductionWebRootManager
    {
        public function __construct(private readonly object $events) {}

        public function prepare(Instance $instance): void
        {
            $this->events->all[] = 'web-roots';
        }
    });

    return [$instance->refresh(), $events];
}

function web_root_route(Instance $instance, string $domain, ?string $webRoot = null): Route
{
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'domain' => $domain,
        ...($webRoot === null ? [] : ['web_root' => $webRoot]),
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    return $route->refresh();
}

/** @return Collection<string, DevelopmentSite> */
function web_root_sites(Node $node): Collection
{
    return new DevelopmentSiteRepository()->forNode($node)->keyBy('domain');
}
