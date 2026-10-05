<?php

declare(strict_types=1);

use App\Actions\Instances\CloneInstanceAction;
use App\Actions\Instances\CloneInstanceEnvironmentAction;
use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Instances\RemoveInstanceAction;
use App\Data\Instances\CloneInstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\CloneCandidateSource;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceEnvironmentValidator;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\InstanceCloneCandidateInspector;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionCloneRouteProjector;
use App\Domain\Instances\ProductionInstanceSourceLifecycle;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Instances\Sqlite\InstanceSqliteSeeder;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSeedResult;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleRenderer;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Schedules\RemoteScheduleRuntimeManager;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\Schedule;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->orbitApp = Project::query()->create([
        'name' => 'Clone domain',
        'slug' => 'clone-domain',
        'repository_url' => 'https://example.test/clone-domain.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->candidateNode = orb198_clone_node('candidate', '10.44.20.10');
    $this->candidateNode->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $this->targetNode = orb198_clone_node('target', '10.44.20.11', 'prod.orbit');
    $this->targetNode->roles()->create([
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);
    $this->candidate = Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->candidateNode->id,
        'name' => 'candidate',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/clone-domain',
        'root' => null,
        'branch' => 'main',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $candidateRoute = Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->candidateNode->id,
        'domain' => 'candidate.dev.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $candidateRoute->targets()->create([
        'instance_id' => $this->candidate->id,
        'position' => 0,
    ]);
    $candidateRoute->update(['status' => RouteStatus::Active]);
    $this->candidate->environmentValues()->create([
        'env_key' => 'APP_KEY',
        'env_value' => 'base64:literal-candidate-key',
    ]);
    $this->candidate->environmentValues()->create([
        'env_key' => 'APP_URL',
        'env_value' => 'https://{{instance.domain}}/{{instance.environment}}',
    ]);
    $this->lock = new Orb198EnvironmentLock;
    $this->sourceLock = new Orb198SourceLock;
    $this->inspector = new Orb198CandidateInspector;
    $this->source = new Orb198ProductionSource;
    $this->writer = new Orb198DomainCloneEnvironmentWriter;
    $this->sqlite = new Orb198SqliteSeeder;
    $this->projection = new Orb198ProductionProjection;
    $this->cloneProjection = new Orb198CloneProjection;
    $this->projectionOwner = new Orb198ProjectionOwner;
    $contexts = new InstanceEnvironmentContextResolver;
    $store = new InstanceEnvironmentStore($contexts, new InstanceEnvironmentValidator);
    $this->environment = new CloneInstanceEnvironmentAction(
        $this->lock,
        $contexts,
        $store,
        new Orb198EnvironmentPreflight,
        new InstanceEnvironmentRenderer,
        $this->writer,
    );
    $this->metrics = Mockery::spy(MetricsFleetReconciler::class);
    $this->action = new CloneInstanceAction(
        $this->inspector,
        $this->lock,
        $this->sourceLock,
        $this->source,
        new RouteStateResolver,
        $this->environment,
        $this->sqlite,
        app(InstantiateProjectRuntimeDefinitionsAction::class),
        $this->projection,
        $this->cloneProjection,
        $this->projectionOwner,
        $this->metrics,
    );
    $this->data = new CloneInstanceData(
        nodeId: $this->targetNode->id,
        name: 'preview',
        previewName: 'shop.com',
        branch: null,
        sqliteSourcePath: '/srv/orbit/apps/clone-domain/database.sqlite',
    );
});

it('publishes a public clone and reconciles once after all clone locks release', function (): void {
    app()->instance(MetricsFleetReconciler::class, $this->metrics);
    $this->cloneProjection->onDns = static function (Route $route): void {
        $route->update(['publication' => RoutePublication::Public]);
    };
    $this->metrics->shouldReceive('reconcile')->once()->andReturnUsing(function (): never {
        expect($this->lock->depth)
            ->toBe(0)
            ->and($this->sourceLock->depth)
            ->toBe(0)
            ->and($this->projectionOwner->depth)
            ->toBe(0);

        throw new RuntimeException('metrics unavailable');
    });

    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(RuntimeException::class, 'metrics unavailable');

    $target = Instance::query()->where('name', 'preview')->sole();
    expect($target->status)->toBe(InstanceState::Active)
        ->and($target->clone_completed_at)->not->toBeNull()
        ->and($target->failed_step)->toBeNull()
        ->and($target->error_code)->toBeNull();
});

it('prepares an independent production target and activates its explicit private preview', function (): void {
    $result = $this->action->execute($this->candidate, $this->data);
    $this->metrics->shouldHaveReceived('reconcile')->once();
    $target = $result['instance'];
    $route = $target->routes->sole();

    expect($result['created'])->toBeTrue()
        ->and($target->status)->toBe(InstanceState::Active)
        ->and($target->provisioning_step)->toBe('active')
        ->and($target->clone_completed_at)->not->toBeNull()
        ->and($target->clone_candidate_id)->toBe($this->candidate->id)
        ->and($target->clone_candidate_commit)->toBe(str_repeat('a', 40))
        ->and($target->branch)->toBe('main')
        ->and($target->branch_override)->toBeNull()
        ->and($target->starting_commit)->toBe(str_repeat('b', 40))
        ->and($target->deployment_branch)->toBeNull()
        ->and($target->checkout_path)->toBe("/home/orbit-app-{$this->orbitApp->id}/releases/initial")
        ->and($route->domain)->toBe('shop.com.prod.orbit')
        ->and($route->provenance)->toBe(RouteProvenance::Explicit)
        ->and($route->publication)->toBe(RoutePublication::Private)
        ->and($route->status)->toBe(RouteStatus::Active)
        ->and($target->runtime_definitions_captured_at)->not->toBeNull();

    expect($this->source->calls)->toBe(['user', 'source', 'resolve', 'profile', 'caddy-access'])
        ->and($this->projection->calls)
        ->toBe(['runtime', 'certificate', 'firewall', 'runtime', 'certificate', 'firewall'])
        ->and($this->cloneProjection->calls)
        ->toBe([
            'workload-caddy',
            'router-certificate',
            'route-firewall',
            'workload',
            'router-caddy',
            'workload-caddy',
            'router-certificate',
            'route-firewall',
            'workload',
            'router-caddy',
            'dns',
        ])
        ->and($this->sqlite->calls)->toHaveCount(1)
        ->and($this->writer->contents)
        ->toBe("APP_DEBUG=\"false\"\nAPP_ENV=\"production\"\nAPP_KEY=\"base64:literal-candidate-key\"\nAPP_URL=\"https://shop.com.prod.orbit/production\"\n")
        ->and($this->writer->observedRouteStatus)->toBe(RouteStatus::Pending)
        ->and($this->writer->observedTargetStatus)->toBe(InstanceState::SourceResolved);

    $sourceValues = $this->candidate->environmentValues()->orderBy('env_key')->get();
    $targetValues = $target->environmentValues()->orderBy('env_key')->get();
    expect($targetValues->pluck('env_value', 'env_key')->except(['APP_DEBUG', 'APP_ENV'])->all())
        ->toBe($sourceValues->pluck('env_value', 'env_key')->except(['APP_DEBUG', 'APP_ENV'])->all())
        ->and($targetValues->firstWhere('env_key', 'APP_ENV')?->env_value)->toBe('production')
        ->and($targetValues->firstWhere('env_key', 'APP_DEBUG')?->env_value)->toBe('false')
        ->and($targetValues->pluck('id')->intersect($sourceValues->pluck('id'))->all())->toBeEmpty()
        ->and($this->lock->owners)->toContain([$this->candidate->id], [$this->candidate->id, $target->id]);
});

it('captures production runtime definitions during clone and installs them only after a release is selected', function (): void {
    $this->orbitApp->processDefinitions()->create([
        'name' => 'queue',
        'environments' => ['production'],
        'spec' => [
            'runtime' => 'systemd',
            'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
        ],
    ]);
    $this->orbitApp->scheduleDefinitions()->create([
        'name' => 'cleanup',
        'environments' => ['production'],
        'spec' => [
            'command' => '/usr/bin/php artisan schedule:run',
            'calendar' => 'daily',
            'timeout_seconds' => 60,
        ],
    ]);

    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(1, '', '', 1, false),
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', '', 1, false),
        ...array_fill(0, 12, new CommandResult(0, '', '', 1, false)),
    ]);
    $accounts = new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount($node->user, $node->user, '/home/'.$node->user);
        }
    };
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 test';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $processRuntime = new RemoteProcessRuntimeManager(
        new ProcessTargetResolver,
        $accounts,
        $ssh,
        $keys,
        $knownHosts,
        new SystemdProcessRenderer,
        new DockerProcessRenderer,
    );
    $certificates = new class implements LeafCertificateSigner
    {
        public function sign(string $hostname, string $certificateRequest): string
        {
            return '';
        }

        public function rootCertificate(): string
        {
            return 'test-root-certificate';
        }
    };
    $scheduleRuntime = new RemoteScheduleRuntimeManager(
        new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver),
        new ScheduleRenderer($certificates),
        $ssh,
        $keys,
        $knownHosts,
    );
    $definitions = new InstantiateProjectRuntimeDefinitionsAction(
        app(ProcessAdmissionLock::class),
        new ProcessTargetResolver,
        new ProcessSpecification,
        $processRuntime,
        $scheduleRuntime,
    );
    $this->action = new CloneInstanceAction(
        $this->inspector,
        $this->lock,
        new Orb198SourceLock,
        $this->source,
        new RouteStateResolver,
        $this->environment,
        $this->sqlite,
        $definitions,
        $this->projection,
        $this->cloneProjection,
        $this->projectionOwner,
        $this->metrics,
    );

    $result = $this->action->execute($this->candidate, $this->data);
    $target = $result['instance'];
    $process = Process::query()->where('owner_id', $target->id)->sole();
    $schedule = Schedule::query()->where('target_id', $target->id)->sole();

    expect($ssh->commands)
        ->toBeEmpty()
        ->and($target->runtime_definitions_captured_at)
        ->not->toBeNull()
        ->and($process->desired_state)
        ->toBe(DesiredProcessState::Stopped)
        ->and($process->status)
        ->toBe(LifecycleStatus::Provisioning)
        ->and($schedule->desired_timer_state)
        ->toBe(DesiredTimerState::Disabled)
        ->and($schedule->status)
        ->toBe(LifecycleStatus::Provisioning);

    expect(fn () => $definitions->installCaptured($target))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('process.release_unavailable');
        });
    expect($ssh->commands[0]->arguments)
        ->toBe(['sudo', 'test', '-d', $target->production_home.'/current']);

    $target->update(['checkout_path' => $target->production_home.'/releases/first']);
    $definitions->installCaptured($target->refresh());

    expect($process->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($process->desired_state)
        ->toBe(DesiredProcessState::Stopped)
        ->and($schedule->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($schedule->desired_timer_state)
        ->toBe(DesiredTimerState::Disabled)
        ->and(array_map(static fn ($command): array => $command->arguments, $ssh->commands))
        ->toContain(
            ['sudo', 'test', '-d', $target->production_home.'/current'],
            ['sudo', '-u', $target->production_user, 'test', '-d', $target->production_home.'/current'],
        );
});

it('prepares a production target from an eligible production candidate', function (): void {
    $user = "orbit-app-{$this->orbitApp->id}";
    $this->candidate->update([
        'production_user' => $user,
        'production_home' => "/home/{$user}",
        'checkout_path' => "/home/{$user}/releases/20260914000000",
        'deployment_branch' => 'main',
    ]);

    $result = $this->action->execute($this->candidate->refresh(), $this->data);
    $target = $result['instance'];

    expect($result['created'])->toBeTrue()
        ->and($target->status)->toBe(InstanceState::Active)
        ->and($target->clone_candidate_id)->toBe($this->candidate->id)
        ->and($target->placedOnAppProd())->toBeTrue()
        ->and($this->candidate->refresh()->status)->toBe(InstanceState::Active)
        ->and($this->candidate->defaultAppEnv())->toBe('development');
});

it('prepares a Cluster-scoped preview with the production Node TLD', function (): void {
    $cluster = Cluster::query()->create([
        'name' => 'clone-cluster',
        'tld' => 'cluster.orbit',
        'state' => ClusterState::Active,
    ]);
    $this->targetNode->update(['cluster_id' => $cluster->id]);
    $router = orb198_clone_node('router', '10.44.20.12');
    $router->update(['cluster_id' => $cluster->id]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);

    $result = $this->action->execute($this->candidate, $this->data);
    $target = $result['instance'];
    $route = $target->routes->sole();

    expect($target->status)->toBe(InstanceState::Active)
        ->and($route->node_id)->toBeNull()
        ->and($route->cluster_id)->toBe($cluster->id)
        ->and($route->domain)->toBe('shop.com.prod.orbit')
        ->and($route->publication)->toBe(RoutePublication::Private)
        ->and($route->targets)->toHaveCount(1)
        ->and($route->targets->sole()->instance_id)->toBe($target->id)
        ->and($this->sqlite->calls)->toHaveCount(1)
        ->and($this->writer->contents)
        ->toBe("APP_DEBUG=\"false\"\nAPP_ENV=\"production\"\nAPP_KEY=\"base64:literal-candidate-key\"\nAPP_URL=\"https://shop.com.prod.orbit/production\"\n");
});

it('refuses a Cluster-scoped destination without an active Router before reservation', function (): void {
    $cluster = Cluster::query()->create([
        'name' => 'clone-cluster-without-router',
        'tld' => 'cluster.orbit',
        'state' => ClusterState::Active,
    ]);
    $this->targetNode->update(['cluster_id' => $cluster->id]);

    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('route.router_required');
        });

    expect(Instance::query()->where('name', 'preview')->exists())->toBeFalse()
        ->and(Route::query()->where('domain', 'shop.com.prod.orbit')->exists())->toBeFalse()
        ->and($this->source->calls)->toBeEmpty()
        ->and($this->writer->contents)->toBeNull();
});

it('resumes one Cluster-scoped target without duplicate Route state', function (): void {
    $cluster = Cluster::query()->create([
        'name' => 'clone-cluster-retry',
        'tld' => 'cluster.orbit',
        'state' => ClusterState::Active,
    ]);
    $this->targetNode->update(['cluster_id' => $cluster->id]);
    $router = orb198_clone_node('retry-router', '10.44.20.12');
    $router->update(['cluster_id' => $cluster->id]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $this->cloneProjection->fail = 'workload';

    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(ResourceOperationException::class);

    $targetId = Instance::query()->where('name', 'preview')->sole()->id;
    $this->cloneProjection->fail = null;
    $result = $this->action->execute($this->candidate, $this->data);
    $route = $result['instance']->routes->sole();

    expect($result['created'])->toBeFalse()
        ->and($result['instance']->id)->toBe($targetId)
        ->and($route->cluster_id)->toBe($cluster->id)
        ->and($route->node_id)->toBeNull()
        ->and(Instance::query()->where('name', 'preview')->count())->toBe(1)
        ->and(Route::query()->where('domain', 'shop.com.prod.orbit')->count())->toBe(1)
        ->and($route->targets()->count())->toBe(1);
});

it('prepares no PHP runtime or SQLite database when neither applies', function (): void {
    $this->source->phpVersion = null;
    $this->source->laravel = false;
    InstanceEnvironmentValue::query()->delete();
    $data = new CloneInstanceData(
        nodeId: $this->targetNode->id,
        name: 'static-preview',
        previewName: 'static',
        branch: 'release',
        sqliteSourcePath: null,
    );

    $result = $this->action->execute($this->candidate, $data);

    expect($result['instance']->status)->toBe(InstanceState::Active)
        ->and($result['instance']->branch)->toBe('release')
        ->and($result['instance']->branch_override)->toBe('release')
        ->and($result['instance']->selected_php_version)->toBeNull()
        ->and($result['instance']->production_php_service)->toBeNull()
        ->and($this->projection->calls)->toBe(['certificate', 'firewall', 'certificate', 'firewall'])
        ->and($this->sqlite->calls)->toBeEmpty()
        ->and($this->writer->contents)->toBe("APP_DEBUG=\"false\"\nAPP_ENV=\"production\"\n");
});

it('keeps every failed production projection inactive at its last completed checkpoint', function (
    string $failure,
    string $checkpoint,
): void {
    if (in_array($failure, ['runtime', 'certificate', 'firewall'], strict: true)) {
        $this->projection->fail = $failure;
    } else {
        $this->cloneProjection->fail = $failure;
    }

    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(ResourceOperationException::class);

    $target = Instance::query()->where('name', 'preview')->sole();
    $route = $target->routes()->sole();
    expect($target->status)->toBe(InstanceState::SourceResolved)
        ->and($target->provisioning_step)->toBe($checkpoint)
        ->and($target->clone_completed_at)->toBeNull()
        ->and($route->status)->toBe(RouteStatus::Failed);
})->with([
    'dedicated runtime' => ['runtime', 'clone-definitions-instantiated'],
    'certificate' => ['certificate', 'clone-runtime-prepared'],
    'firewall' => ['firewall', 'clone-certificate-prepared'],
    'workload Caddy' => ['workload-caddy', 'clone-firewall-prepared'],
    'Router certificate' => ['router-certificate', 'clone-workload-caddy-published'],
    'Route firewall' => ['route-firewall', 'clone-router-certificate-prepared'],
    'workload certificate verification' => ['workload', 'clone-route-firewall-prepared'],
    'Router Caddy' => ['router-caddy', 'clone-workload-verified'],
    'private DNS' => ['dns', 'clone-router-caddy-published'],
]);

it('keeps DNS unpublished when the locked second projection pass fails', function (): void {
    $this->cloneProjection->failOnOccurrence = ['workload' => 2];

    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(ResourceOperationException::class);

    $target = Instance::query()->where('name', 'preview')->sole();
    $route = $target->routes()->sole();
    expect($target->status)->toBe(InstanceState::SourceResolved)
        ->and($target->provisioning_step)->toBe('clone-router-caddy-published')
        ->and($route->status)->toBe(RouteStatus::Failed)
        ->and($this->cloneProjection->calls)->not->toContain('dns')
        ->and(collect($this->cloneProjection->calls)->filter(
            static fn (string $operation): bool => $operation === 'workload',
        ))->toHaveCount(2);
});

it('resumes one owned target and makes the completed identical retry terminal', function (): void {
    $this->cloneProjection->fail = 'dns';
    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(ResourceOperationException::class);

    $target = Instance::query()->where('name', 'preview')->sole();
    $targetId = $target->id;
    $this->cloneProjection->fail = null;
    $resumed = $this->action->execute($this->candidate, $this->data);

    expect($resumed['created'])->toBeFalse()
        ->and($resumed['instance']->id)->toBe($targetId)
        ->and($resumed['instance']->status)->toBe(InstanceState::Active)
        ->and(Instance::query()->where('name', 'preview')->count())->toBe(1);

    $resumed['instance']->environmentValues()->where('env_key', 'APP_KEY')->sole()->update([
        'env_value' => 'base64:target-edited-key',
    ]);
    $route = $resumed['instance']->routes()->sole();
    $replacement = Route::query()->create([
        'project_id' => $route->project_id,
        'node_id' => $route->node_id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'final.example.test',
        'provenance' => $route->provenance,
        'publication' => $route->publication,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $route->id,
        'replacement_step' => RouteReplacementStep::Reserved,
    ]);
    $replacement->targets()->create([
        'instance_id' => $resumed['instance']->id,
        'position' => 0,
    ]);
    $route->update(['replaced_by_route_id' => $replacement->id]);
    $replacement->update(['status' => RouteStatus::Activating]);
    $route->update(['status' => RouteStatus::Retiring]);
    $route->targets()->delete();
    $route->delete();
    $replacement->update([
        'status' => RouteStatus::Active,
        'replaces_route_id' => null,
        'replacement_step' => null,
    ]);
    $inspectionCount = $this->inspector->calls;
    $this->inspector->fail = true;
    $terminal = $this->action->execute($this->candidate, $this->data);

    expect($terminal['created'])->toBeFalse()
        ->and($terminal['instance']->id)->toBe($targetId)
        ->and($terminal['instance']->routes->sole()->domain)->toBe('final.example.test')
        ->and($terminal['instance']->environmentValues()->where('env_key', 'APP_KEY')->sole()->env_value)
        ->toBe('base64:target-edited-key')
        ->and($this->inspector->calls)->toBe($inspectionCount);

    $conflict = new CloneInstanceData(
        nodeId: $this->targetNode->id,
        name: 'preview',
        previewName: 'shop.com',
        branch: null,
        sqliteSourcePath: '/srv/orbit/apps/clone-domain/other.sqlite',
    );
    expect(fn () => $this->action->execute($this->candidate, $conflict))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.clone_retry_conflict');
        });
});

it('resumes an interrupted target after the candidate commit advances', function (): void {
    $this->cloneProjection->fail = 'dns';
    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(ResourceOperationException::class);

    $target = Instance::query()->where('name', 'preview')->sole();
    $this->inspector->commit = str_repeat('c', 40);
    $this->cloneProjection->fail = null;

    $resumed = $this->action->execute($this->candidate, $this->data);

    expect($resumed['created'])->toBeFalse()
        ->and($resumed['instance']->id)->toBe($target->id)
        ->and($resumed['instance']->status)->toBe(InstanceState::Active)
        ->and($resumed['instance']->clone_candidate_commit)->toBe(str_repeat('a', 40));
});

it('refuses an invalid or occupied destination preview before target reservation', function (string $case): void {
    if ($case === 'missing TLD') {
        $this->targetNode->update(['tld' => null]);
    } else {
        Route::query()->create([
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->targetNode->id,
            'domain' => 'shop.com.prod.orbit',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
    }

    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(ResourceOperationException::class);

    expect(Instance::query()->where('name', 'preview')->exists())->toBeFalse()
        ->and($this->source->calls)->toBeEmpty()
        ->and($this->writer->contents)->toBeNull();
})->with(['missing TLD', 'occupied hostname']);

it('refuses a route publication step when the Project no longer requires a Route', function (): void {
    $this->projection->fail = 'certificate';
    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(ResourceOperationException::class);

    $this->projection->fail = null;
    $this->orbitApp->update(['type' => ProjectType::NodePackage, 'root' => null]);

    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(ResourceOperationException::class, 'The clone preview Route changed.');

    $target = Instance::query()->where('name', 'preview')->sole();
    expect($target->provisioning_step)->toBe('clone-runtime-prepared')
        ->and($target->error_code)->toBe('instance.lifecycle_conflict');
});

it('refuses candidate removal while an incomplete clone retains its source dependency', function (): void {
    $this->cloneProjection->fail = 'router-caddy';
    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(ResourceOperationException::class);

    expect(fn () => app(RemoveInstanceAction::class)->execute($this->candidate, false))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.clone_in_progress');
        });

    expect($this->candidate->refresh()->status)->toBe(InstanceState::Active)
        ->and(Instance::query()->where('name', 'preview')->sole()->clone_completed_at)->toBeNull();
});

function orb198_clone_node(string $name, string $address, ?string $tld = null): Node
{
    return Node::query()->create([
        'name' => "clone-domain-{$name}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => $tld,
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => $address,
        'user' => 'orbit',
    ]);
}

final class Orb198CandidateInspector implements InstanceCloneCandidateInspector
{
    public int $calls = 0;

    public bool $fail = false;

    public string $commit = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function inspect(Instance $candidate, string $targetBranch): CloneCandidateSource
    {
        $this->calls++;

        if ($this->fail) {
            throw new ResourceOperationException('instance.clone_candidate_dirty', 'Candidate changed.', 409);
        }

        $candidate->loadMissing('node');

        return new CloneCandidateSource(
            instanceId: $candidate->id,
            environment: $candidate->defaultAppEnv(),
            basePath: $candidate->checkout_path,
            executionUser: $candidate->node->user,
            branch: (string) $candidate->branch,
            commit: $this->commit,
            node: $candidate->node,
        );
    }
}

final class Orb198ProductionSource implements ProductionInstanceSourceLifecycle
{
    /** @var list<string> */
    public array $calls = [];

    public ?string $phpVersion = '8.5';

    public bool $laravel = true;

    public function prepareUser(Instance $instance): void
    {
        $this->calls[] = 'user';
    }

    public function prepareSource(Instance $instance, bool $allowExisting): void
    {
        $this->calls[] = 'source';
    }

    public function resolve(Instance $instance): DevelopmentSourceResolution
    {
        $this->calls[] = 'resolve';

        return new DevelopmentSourceResolution((string) $instance->branch, str_repeat('b', 40));
    }

    public function inspectProfile(Instance $instance): DevelopmentSourceProfile
    {
        $this->calls[] = 'profile';

        return new DevelopmentSourceProfile($this->phpVersion, $this->laravel);
    }

    public function prepareCaddyAccess(Instance $instance): void
    {
        $this->calls[] = 'caddy-access';
    }
}

final class Orb198EnvironmentLock implements InstanceEnvironmentOperationLock
{
    /** @var list<list<int>> */
    public array $owners = [];

    public int $depth = 0;

    public function run(array $instanceIds, Closure $operation): mixed
    {
        $owners = array_values(array_unique(array_map(intval(...), $instanceIds)));
        sort($owners, SORT_NUMERIC);
        $this->owners[] = $owners;
        $this->depth++;

        try {
            return $operation();
        } finally {
            $this->depth--;
        }
    }
}

final class Orb198SourceLock implements AppDevSourceOperationLock
{
    public int $depth = 0;

    public function synchronized(int $nodeId, Closure $operation): mixed
    {
        $this->depth++;

        try {
            return $operation();
        } finally {
            $this->depth--;
        }
    }
}

final class Orb198EnvironmentPreflight implements InstanceOperationPreflight
{
    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void {}

    public function assertEnvironmentWritable(InstanceEnvironmentContext $context, int $requiredCapacityBytes): void {}
}

final class Orb198DomainCloneEnvironmentWriter implements InstanceEnvironmentWriter
{
    public ?string $contents = null;

    public ?RouteStatus $observedRouteStatus = null;

    public ?InstanceState $observedTargetStatus = null;

    public function write(InstanceEnvironmentContext $context, string $contents): InstanceEnvironmentWriteResult
    {
        $this->contents = $contents;
        $this->observedRouteStatus = Route::query()->findOrFail($context->routeId)->status;
        $this->observedTargetStatus = Instance::query()->findOrFail($context->instanceId)->status;

        return InstanceEnvironmentWriteResult::changed();
    }
}

final class Orb198SqliteSeeder implements InstanceSqliteSeeder
{
    public function abandon(SqliteSeedPlacement $source, SqliteSeedPlacement $target, string $sourcePath): bool
    {
        throw new LogicException('Cloning retains its seed for identical retries.');
    }

    /** @var list<array{source: SqliteSeedPlacement, target: SqliteSeedPlacement, path: string}> */
    public array $calls = [];

    public function seed(SqliteSeedPlacement $source, SqliteSeedPlacement $target, string $sourcePath): SqliteSeedResult
    {
        $this->calls[] = ['source' => $source, 'target' => $target, 'path' => $sourcePath];

        return SqliteSeedResult::changed();
    }
}

final class Orb198ProductionProjection implements ProductionRouteProjector
{
    /** @var list<string> */
    public array $calls = [];

    public ?string $fail = null;

    public function prepareRuntime(Instance $instance, Route $route): void
    {
        $this->record('runtime');
    }

    public function prepareCertificate(Instance $instance, Route $route): void
    {
        $this->record('certificate');
    }

    public function prepareFirewall(Instance $instance): void
    {
        $this->record('firewall');
    }

    public function publish(Instance $instance, Route $route): void
    {
        throw new LogicException('Clone publication must use the split projector.');
    }

    private function record(string $operation): void
    {
        $this->calls[] = $operation;

        if ($this->fail === $operation) {
            throw new ResourceOperationException("instance.clone_{$operation}_failed", 'Injected failure.', 409);
        }
    }
}

final class Orb198CloneProjection implements ProductionCloneRouteProjector
{
    /** @var list<string> */
    public array $calls = [];

    public ?Closure $onDns = null;

    public ?string $fail = null;

    /** @var array<string, positive-int> */
    public array $failOnOccurrence = [];

    public function prepareWorkloadCaddy(Instance $instance, Route $route): void
    {
        $this->record('workload-caddy');
    }

    public function prepareRouterCertificate(Instance $instance, Route $route): void
    {
        $this->record('router-certificate');
    }

    public function prepareRouteFirewall(Instance $instance, Route $route): void
    {
        $this->record('route-firewall');
    }

    public function verifyWorkload(Instance $instance, Route $route): void
    {
        $this->record('workload');
    }

    public function prepareRouterCaddy(Instance $instance, Route $route): void
    {
        $this->record('router-caddy');
    }

    public function prepareDns(Route $route): void
    {
        $this->record('dns');

        if ($this->onDns instanceof Closure) {
            ($this->onDns)($route);
        }
    }

    private function record(string $operation): void
    {
        $this->calls[] = $operation;
        $occurrence = collect($this->calls)
            ->filter(static fn (string $call): bool => $call === $operation)
            ->count();

        if (
            $this->fail === $operation
            || ($this->failOnOccurrence[$operation] ?? null) === $occurrence
        ) {
            throw new ResourceOperationException("instance.clone_{$operation}_failed", 'Injected failure.', 409);
        }
    }
}

final class Orb198ProjectionOwner implements DevelopmentProjectionOperationLock
{
    public int $depth = 0;

    public function run(Closure $operation): mixed
    {
        $this->depth++;

        try {
            return $operation();
        } finally {
            $this->depth--;
        }
    }
}
