<?php

declare(strict_types=1);

use App\Actions\AppInstances\CloneAppInstanceAction;
use App\Actions\AppInstances\CloneAppInstanceEnvironmentAction;
use App\Actions\AppInstances\InstantiateAppRuntimeDefinitionsAction;
use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Data\AppInstances\CloneAppInstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\AppInstanceCloneCandidateInspector;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\CloneCandidateSource;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRenderer;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentValidator;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriter;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriteResult;
use App\Domain\AppInstances\Environment\AppInstanceOperationPreflight;
use App\Domain\AppInstances\ProductionAppInstanceSourceLifecycle;
use App\Domain\AppInstances\ProductionCloneRouteProjector;
use App\Domain\AppInstances\ProductionRouteProjector;
use App\Domain\AppInstances\Sqlite\AppInstanceSqliteSeeder;
use App\Domain\AppInstances\Sqlite\SqliteSeedPlacement;
use App\Domain\AppInstances\Sqlite\SqliteSeedResult;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\AppProdSiteRepository;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;

beforeEach(function (): void {
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Clone domain',
        'slug' => 'clone-domain',
        'repository_url' => 'https://example.test/clone-domain.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->candidateNode = orb198_clone_node('candidate', '10.44.20.10');
    $this->targetNode = orb198_clone_node('target', '10.44.20.11', 'prod.orbit');
    $this->targetNode->roles()->create([
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);
    $this->candidate = AppInstance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->candidateNode->id,
        'name' => 'candidate',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/clone-domain',
        'root' => null,
        'branch' => 'main',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $candidateRoute = Route::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->candidateNode->id,
        'hostname' => 'candidate.dev.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $candidateRoute->targets()->create([
        'app_instance_id' => $this->candidate->id,
        'position' => 0,
    ]);
    $candidateRoute->update(['status' => RouteStatus::Active]);
    $this->candidate->environmentValues()->create([
        'env_key' => 'APP_KEY',
        'env_value' => 'base64:literal-candidate-key',
    ]);
    $this->candidate->environmentValues()->create([
        'env_key' => 'APP_URL',
        'env_value' => 'https://{{app_instance.hostname}}/{{app_instance.environment}}',
    ]);
    $this->lock = new Orb198EnvironmentLock;
    $this->inspector = new Orb198CandidateInspector;
    $this->source = new Orb198ProductionSource;
    $this->writer = new Orb198DomainCloneEnvironmentWriter;
    $this->sqlite = new Orb198SqliteSeeder;
    $this->projection = new Orb198ProductionProjection;
    $this->cloneProjection = new Orb198CloneProjection;
    $this->projectionOwner = new Orb198ProjectionOwner;
    $contexts = new AppInstanceEnvironmentContextResolver;
    $store = new AppInstanceEnvironmentStore($contexts, new AppInstanceEnvironmentValidator);
    $environment = new CloneAppInstanceEnvironmentAction(
        $this->lock,
        $contexts,
        $store,
        new Orb198EnvironmentPreflight,
        new AppInstanceEnvironmentRenderer,
        $this->writer,
    );
    $this->action = new CloneAppInstanceAction(
        $this->inspector,
        $this->lock,
        new Orb198SourceLock,
        $this->source,
        new RouteStateResolver,
        new AppProdSiteRepository,
        $environment,
        $this->sqlite,
        app(InstantiateAppRuntimeDefinitionsAction::class),
        $this->projection,
        $this->cloneProjection,
        $this->projectionOwner,
    );
    $this->data = new CloneAppInstanceData(
        nodeId: $this->targetNode->id,
        name: 'preview',
        previewName: 'shop.com',
        branch: null,
        sqliteSourcePath: '/srv/orbit/apps/clone-domain/database.sqlite',
    );
});

it('prepares an independent production target and activates its explicit private preview', function (): void {
    $result = $this->action->execute($this->candidate, $this->data);
    $target = $result['appInstance'];
    $route = $target->routes->sole();

    expect($result['created'])->toBeTrue()
        ->and($target->status)->toBe(AppInstanceState::Active)
        ->and($target->provisioning_step)->toBe('active')
        ->and($target->clone_completed_at)->not->toBeNull()
        ->and($target->clone_candidate_id)->toBe($this->candidate->id)
        ->and($target->clone_candidate_commit)->toBe(str_repeat('a', 40))
        ->and($target->branch)->toBe('main')
        ->and($target->branch_override)->toBeNull()
        ->and($target->starting_commit)->toBe(str_repeat('b', 40))
        ->and($target->deployment_branch)->toBeNull()
        ->and($target->checkout_path)->toBe("/home/orbit-app-{$this->orbitApp->id}/releases/initial")
        ->and($route->hostname)->toBe('shop.com.prod.orbit')
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
            'dns',
            'workload-caddy',
            'router-certificate',
            'route-firewall',
            'workload',
            'router-caddy',
            'dns',
        ])
        ->and($this->sqlite->calls)->toHaveCount(1)
        ->and($this->writer->contents)
        ->toBe("APP_KEY=\"base64:literal-candidate-key\"\nAPP_URL=\"https://shop.com.prod.orbit/production\"\n")
        ->and($this->writer->observedRouteStatus)->toBe(RouteStatus::Pending)
        ->and($this->writer->observedTargetStatus)->toBe(AppInstanceState::SourceResolved);

    $sourceValues = $this->candidate->environmentValues()->orderBy('env_key')->get();
    $targetValues = $target->environmentValues()->orderBy('env_key')->get();
    expect($targetValues->pluck('env_key', 'env_key')->all())->toBe($sourceValues->pluck('env_key', 'env_key')->all())
        ->and($targetValues->pluck('env_value', 'env_key')->all())->toBe($sourceValues->pluck('env_value', 'env_key')->all())
        ->and($targetValues->pluck('id')->intersect($sourceValues->pluck('id'))->all())->toBeEmpty()
        ->and($this->lock->owners)->toContain([$this->candidate->id], [$this->candidate->id, $target->id]);
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
    $target = $result['appInstance'];
    $route = $target->routes->sole();

    expect($target->status)->toBe(AppInstanceState::Active)
        ->and($route->node_id)->toBeNull()
        ->and($route->cluster_id)->toBe($cluster->id)
        ->and($route->hostname)->toBe('shop.com.prod.orbit')
        ->and($route->publication)->toBe(RoutePublication::Private)
        ->and($route->targets)->toHaveCount(1)
        ->and($route->targets->sole()->app_instance_id)->toBe($target->id)
        ->and($this->sqlite->calls)->toHaveCount(1)
        ->and($this->writer->contents)
        ->toBe("APP_KEY=\"base64:literal-candidate-key\"\nAPP_URL=\"https://shop.com.prod.orbit/production\"\n");
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

    expect(AppInstance::query()->where('name', 'preview')->exists())->toBeFalse()
        ->and(Route::query()->where('hostname', 'shop.com.prod.orbit')->exists())->toBeFalse()
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

    $targetId = AppInstance::query()->where('name', 'preview')->sole()->id;
    $this->cloneProjection->fail = null;
    $result = $this->action->execute($this->candidate, $this->data);
    $route = $result['appInstance']->routes->sole();

    expect($result['created'])->toBeFalse()
        ->and($result['appInstance']->id)->toBe($targetId)
        ->and($route->cluster_id)->toBe($cluster->id)
        ->and($route->node_id)->toBeNull()
        ->and(AppInstance::query()->where('name', 'preview')->count())->toBe(1)
        ->and(Route::query()->where('hostname', 'shop.com.prod.orbit')->count())->toBe(1)
        ->and($route->targets()->count())->toBe(1);
});

it('prepares no PHP runtime or SQLite database when neither applies', function (): void {
    $this->source->phpVersion = null;
    $this->source->laravel = false;
    AppInstanceEnvironmentValue::query()->delete();
    $data = new CloneAppInstanceData(
        nodeId: $this->targetNode->id,
        name: 'static-preview',
        previewName: 'static',
        branch: 'release',
        sqliteSourcePath: null,
    );

    $result = $this->action->execute($this->candidate, $data);

    expect($result['appInstance']->status)->toBe(AppInstanceState::Active)
        ->and($result['appInstance']->branch)->toBe('release')
        ->and($result['appInstance']->branch_override)->toBe('release')
        ->and($result['appInstance']->selected_php_version)->toBeNull()
        ->and($result['appInstance']->production_php_service)->toBeNull()
        ->and($this->projection->calls)->toBe(['certificate', 'firewall', 'certificate', 'firewall'])
        ->and($this->sqlite->calls)->toBeEmpty()
        ->and($this->writer->contents)->toBe('');
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

    $target = AppInstance::query()->where('name', 'preview')->sole();
    $route = $target->routes()->sole();
    expect($target->status)->toBe(AppInstanceState::SourceResolved)
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

it('resumes one owned target and makes the completed identical retry terminal', function (): void {
    $this->cloneProjection->fail = 'dns';
    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(ResourceOperationException::class);

    $target = AppInstance::query()->where('name', 'preview')->sole();
    $targetId = $target->id;
    $this->cloneProjection->fail = null;
    $resumed = $this->action->execute($this->candidate, $this->data);

    expect($resumed['created'])->toBeFalse()
        ->and($resumed['appInstance']->id)->toBe($targetId)
        ->and($resumed['appInstance']->status)->toBe(AppInstanceState::Active)
        ->and(AppInstance::query()->where('name', 'preview')->count())->toBe(1);

    $resumed['appInstance']->environmentValues()->where('env_key', 'APP_KEY')->sole()->update([
        'env_value' => 'base64:target-edited-key',
    ]);
    $route = $resumed['appInstance']->routes()->sole();
    $route->update(['hostname' => 'final.example.test']);
    $inspectionCount = $this->inspector->calls;
    $this->inspector->fail = true;
    $terminal = $this->action->execute($this->candidate, $this->data);

    expect($terminal['created'])->toBeFalse()
        ->and($terminal['appInstance']->id)->toBe($targetId)
        ->and($terminal['appInstance']->routes->sole()->hostname)->toBe('final.example.test')
        ->and($terminal['appInstance']->environmentValues()->where('env_key', 'APP_KEY')->sole()->env_value)
        ->toBe('base64:target-edited-key')
        ->and($this->inspector->calls)->toBe($inspectionCount);

    $conflict = new CloneAppInstanceData(
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

    $target = AppInstance::query()->where('name', 'preview')->sole();
    $this->inspector->commit = str_repeat('c', 40);
    $this->cloneProjection->fail = null;

    $resumed = $this->action->execute($this->candidate, $this->data);

    expect($resumed['created'])->toBeFalse()
        ->and($resumed['appInstance']->id)->toBe($target->id)
        ->and($resumed['appInstance']->status)->toBe(AppInstanceState::Active)
        ->and($resumed['appInstance']->clone_candidate_commit)->toBe(str_repeat('a', 40));
});

it('refuses an invalid or occupied destination preview before target reservation', function (string $case): void {
    if ($case === 'missing TLD') {
        $this->targetNode->update(['tld' => null]);
    } else {
        Route::query()->create([
            'app_id' => $this->orbitApp->id,
            'node_id' => $this->targetNode->id,
            'hostname' => 'shop.com.prod.orbit',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
    }

    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(ResourceOperationException::class);

    expect(AppInstance::query()->where('name', 'preview')->exists())->toBeFalse()
        ->and($this->source->calls)->toBeEmpty()
        ->and($this->writer->contents)->toBeNull();
})->with(['missing TLD', 'occupied hostname']);

it('refuses candidate removal while an incomplete clone retains its source dependency', function (): void {
    $this->cloneProjection->fail = 'router-caddy';
    expect(fn () => $this->action->execute($this->candidate, $this->data))
        ->toThrow(ResourceOperationException::class);

    expect(fn () => app(RemoveAppInstanceAction::class)->execute($this->candidate, false))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.clone_in_progress');
        });

    expect($this->candidate->refresh()->status)->toBe(AppInstanceState::Active)
        ->and(AppInstance::query()->where('name', 'preview')->sole()->clone_completed_at)->toBeNull();
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

final class Orb198CandidateInspector implements AppInstanceCloneCandidateInspector
{
    public int $calls = 0;

    public bool $fail = false;

    public string $commit = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function inspect(AppInstance $candidate, string $targetBranch): CloneCandidateSource
    {
        $this->calls++;

        if ($this->fail) {
            throw new ResourceOperationException('instance.clone_candidate_dirty', 'Candidate changed.', 409);
        }

        $candidate->loadMissing('node');

        return new CloneCandidateSource(
            appInstanceId: $candidate->id,
            environment: $candidate->environment,
            basePath: $candidate->checkout_path,
            executionUser: $candidate->node->user,
            branch: (string) $candidate->branch,
            commit: $this->commit,
            node: $candidate->node,
        );
    }
}

final class Orb198ProductionSource implements ProductionAppInstanceSourceLifecycle
{
    /** @var list<string> */
    public array $calls = [];

    public ?string $phpVersion = '8.5';

    public bool $laravel = true;

    public function prepareUser(AppInstance $appInstance): void
    {
        $this->calls[] = 'user';
    }

    public function prepareSource(AppInstance $appInstance, bool $allowExisting): void
    {
        $this->calls[] = 'source';
    }

    public function resolve(AppInstance $appInstance): DevelopmentSourceResolution
    {
        $this->calls[] = 'resolve';

        return new DevelopmentSourceResolution((string) $appInstance->branch, str_repeat('b', 40));
    }

    public function inspectProfile(AppInstance $appInstance): DevelopmentSourceProfile
    {
        $this->calls[] = 'profile';

        return new DevelopmentSourceProfile($this->phpVersion, $this->laravel);
    }

    public function prepareCaddyAccess(AppInstance $appInstance): void
    {
        $this->calls[] = 'caddy-access';
    }
}

final class Orb198EnvironmentLock implements AppInstanceEnvironmentOperationLock
{
    /** @var list<list<int>> */
    public array $owners = [];

    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        $owners = array_values(array_unique(array_map(intval(...), $appInstanceIds)));
        sort($owners, SORT_NUMERIC);
        $this->owners[] = $owners;

        return $operation();
    }
}

final class Orb198SourceLock implements AppDevSourceOperationLock
{
    public function synchronized(int $nodeId, Closure $operation): mixed
    {
        return $operation();
    }
}

final class Orb198EnvironmentPreflight implements AppInstanceOperationPreflight
{
    public function assertEnvironmentReadable(AppInstanceEnvironmentContext $context): void {}

    public function assertEnvironmentWritable(AppInstanceEnvironmentContext $context, int $requiredCapacityBytes): void {}
}

final class Orb198DomainCloneEnvironmentWriter implements AppInstanceEnvironmentWriter
{
    public ?string $contents = null;

    public ?RouteStatus $observedRouteStatus = null;

    public ?AppInstanceState $observedTargetStatus = null;

    public function write(AppInstanceEnvironmentContext $context, string $contents): AppInstanceEnvironmentWriteResult
    {
        $this->contents = $contents;
        $this->observedRouteStatus = Route::query()->findOrFail($context->routeId)->status;
        $this->observedTargetStatus = AppInstance::query()->findOrFail($context->appInstanceId)->status;

        return AppInstanceEnvironmentWriteResult::changed();
    }
}

final class Orb198SqliteSeeder implements AppInstanceSqliteSeeder
{
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

    public function prepareRuntime(AppInstance $appInstance, Route $route): void
    {
        $this->record('runtime');
    }

    public function prepareCertificate(AppInstance $appInstance, Route $route): void
    {
        $this->record('certificate');
    }

    public function prepareFirewall(AppInstance $appInstance): void
    {
        $this->record('firewall');
    }

    public function publish(AppInstance $appInstance, Route $route): void
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

    public ?string $fail = null;

    public function prepareWorkloadCaddy(AppInstance $appInstance, Route $route): void
    {
        $this->record('workload-caddy');
    }

    public function prepareRouterCertificate(AppInstance $appInstance, Route $route): void
    {
        $this->record('router-certificate');
    }

    public function prepareRouteFirewall(AppInstance $appInstance, Route $route): void
    {
        $this->record('route-firewall');
    }

    public function verifyWorkload(AppInstance $appInstance, Route $route): void
    {
        $this->record('workload');
    }

    public function prepareRouterCaddy(AppInstance $appInstance, Route $route): void
    {
        $this->record('router-caddy');
    }

    public function prepareDns(Route $route): void
    {
        $this->record('dns');
    }

    private function record(string $operation): void
    {
        $this->calls[] = $operation;

        if ($this->fail === $operation) {
            throw new ResourceOperationException("instance.clone_{$operation}_failed", 'Injected failure.', 409);
        }
    }
}

final class Orb198ProjectionOwner implements DevelopmentProjectionOperationLock
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}
