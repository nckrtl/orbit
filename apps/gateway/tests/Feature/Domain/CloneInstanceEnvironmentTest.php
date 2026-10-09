<?php

declare(strict_types=1);

use App\Actions\Instances\CloneInstanceAction;
use App\Actions\Instances\CloneInstanceEnvironmentAction;
use App\Actions\Instances\DeployInstanceAction;
use App\Actions\Instances\InstanceDeploymentConfigResolver;
use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Data\Instances\CloneInstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Instances\CloneCandidateSource;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceEnvironmentValidator;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\InstanceCloneCandidateInspector;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionCloneRouteProjector;
use App\Domain\Instances\ProductionInstanceSourceLifecycle;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Instances\Sqlite\InstanceSqliteSeeder;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

it('copies independent encrypted values and resolves placeholders through the exact pending clone Route', function (): void {
    [$source, $target] = clone_environment_fixture();
    $source->environmentValues()->createMany([
        ['env_key' => 'APP_ENV', 'env_value' => '{{instance.environment}}'],
        ['env_key' => 'APP_KEY', 'env_value' => 'base64:literal-key'],
        ['env_key' => 'APP_URL', 'env_value' => 'https://{{instance.domain}}/path'],
    ]);
    $sourceCiphertext = DB::table('instance_environment_values')
        ->where('instance_id', $source->id)
        ->orderBy('env_key')
        ->pluck('env_value', 'env_key')
        ->all();
    [$lock, $preflight, $writer] = bind_clone_environment_fakes();

    $result = app(CloneInstanceEnvironmentAction::class)->execute($source, $target);

    $targetValues = $target->environmentValues()->orderBy('env_key')->pluck('env_value', 'env_key')->all();
    $targetCiphertext = DB::table('instance_environment_values')
        ->where('instance_id', $target->id)
        ->orderBy('env_key')
        ->pluck('env_value', 'env_key')
        ->all();
    expect($result->toArray())
        ->toBe([
            'instance_id' => $target->id,
            'operation' => 'clone',
            'changed' => true,
            'key_count' => 4,
        ])
        ->and($targetValues)
        ->toBe([
            'APP_DEBUG' => 'false',
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:literal-key',
            'APP_URL' => 'https://{{instance.domain}}/path',
        ])
        ->and($targetCiphertext)
        ->toHaveKeys(['APP_DEBUG', 'APP_ENV', 'APP_KEY', 'APP_URL']);
    foreach (['APP_KEY', 'APP_URL'] as $key) {
        expect($targetCiphertext[$key])->not->toBe($sourceCiphertext[$key]);
    }
    expect($writer->contents)
        ->toBe("APP_DEBUG=\"false\"\n"
            ."APP_ENV=\"production\"\n"
            ."APP_KEY=\"base64:literal-key\"\n"
            ."APP_URL=\"https://preview.prod.orbit/path\"\n")
        ->and($writer->context?->routeDomain)
        ->toBe('preview.prod.orbit')
        ->and($writer->context?->routeId)
        ->toBe($target->routes()->sole()->id)
        ->and($lock->instanceIds)
        ->toBe([$source->id, $target->id])
        ->and($preflight->requiredCapacityBytes)
        ->toBeGreaterThan(InstanceEnvironmentValidator::MaximumFileBytes);
});

it('clones and deploys an Instance without a route', function (): void {
    [$candidate, $reservedTarget, $targetRoute] = clone_environment_fixture('lifecycle');
    $candidate->project->update(['type' => 'monorepo']);
    $candidate->update(['branch' => 'main', 'source_is_laravel' => false]);
    $candidate->routes()->delete();
    $targetRoute->delete();
    $reservedTarget->delete();
    $targetNode = Node::query()->findOrFail($reservedTarget->node_id);
    orbit_test_set_app_placement_role($targetNode, true);
    $candidate->environmentValues()->createMany([
        ['env_key' => 'APP_ENV', 'env_value' => '{{instance.environment}}'],
        ['env_key' => 'APP_KEY', 'env_value' => 'base64:literal-key'],
    ]);

    $lock = new Orb198CloneEnvironmentLock;
    $preflight = new Orb198CloneEnvironmentPreflight;
    $writer = new Orb198CloneEnvironmentWriter(changed: true);
    app()->instance(InstanceEnvironmentOperationLock::class, $lock);
    app()->instance(InstanceOperationPreflight::class, $preflight);
    app()->instance(InstanceEnvironmentWriter::class, $writer);
    app()->instance(InstanceEnvironmentReader::class, $writer);
    $contexts = new InstanceEnvironmentContextResolver;
    $store = new InstanceEnvironmentStore($contexts, new InstanceEnvironmentValidator);
    $cloneEnvironment = new CloneInstanceEnvironmentAction(
        $lock,
        $contexts,
        $store,
        $preflight,
        new InstanceEnvironmentRenderer,
        $writer,
    );
    $inspector = Mockery::mock(InstanceCloneCandidateInspector::class);
    $inspector->shouldReceive('inspect')->twice()->andReturn(new CloneCandidateSource(
        instanceId: $candidate->id,
        environment: 'development',
        basePath: $candidate->checkout_path,
        executionUser: 'orbit',
        branch: 'main',
        commit: str_repeat('a', 40),
        node: Node::query()->findOrFail($candidate->node_id),
    ));
    $source = Mockery::mock(ProductionInstanceSourceLifecycle::class);
    $source->shouldReceive('prepareUser')->once();
    $source->shouldReceive('prepareSource')->once();
    $source->shouldReceive('resolve')->once()->andReturn(new DevelopmentSourceResolution('main', str_repeat('b', 40)));
    $source->shouldReceive('inspectProfile')->once()->andReturn(new DevelopmentSourceProfile(null, false));
    $source->shouldReceive('prepareCaddyAccess')->once();
    $sourceLock = Mockery::mock(AppDevSourceOperationLock::class);
    $sourceLock->shouldReceive('synchronized')->once()->andReturnUsing(
        static fn (int $nodeId, Closure $operation): mixed => $operation(),
    );
    $sqlite = Mockery::mock(InstanceSqliteSeeder::class)->shouldIgnoreMissing();
    $projection = Mockery::mock(ProductionRouteProjector::class)->shouldIgnoreMissing();
    $cloneProjection = Mockery::mock(ProductionCloneRouteProjector::class)->shouldIgnoreMissing();
    $projectionOwner = Mockery::mock(DevelopmentProjectionOperationLock::class)->shouldIgnoreMissing();
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldReceive('reconcile')->once();
    $clone = new CloneInstanceAction(
        $inspector,
        $lock,
        $sourceLock,
        $source,
        new RouteStateResolver,
        $cloneEnvironment,
        $sqlite,
        app(InstantiateProjectRuntimeDefinitionsAction::class),
        $projection,
        $cloneProjection,
        $projectionOwner,
        $metrics,
    );

    $cloned = $clone->execute($candidate, new CloneInstanceData(
        nodeId: $targetNode->id,
        name: 'route-less-production',
        previewName: 'unused',
        branch: null,
        sqliteSourcePath: null,
    ))['instance'];

    $deployment = Mockery::mock(ProductionDeployment::class);
    $deployment->shouldReceive('selected')->once()->andReturn(null);
    $release = new DeploymentRelease(
        'release-1',
        "{$cloned->production_home}/releases/release-1",
        str_repeat('c', 40),
    );
    $deployment->shouldReceive('prepare')->once()->andReturn($release);
    $deployment->shouldReceive('activate')->once()->andReturn($release);
    $deploy = new DeployInstanceAction(
        new InstanceDeploymentConfigResolver(new InstanceDeployStepStore),
        $lock,
        new SynchronizeInstanceEnvironmentAction(
            $lock,
            $contexts,
            $store,
            $preflight,
            new InstanceEnvironmentRenderer,
            $writer,
        ),
        $deployment,
        Mockery::mock(ProductionPhpRuntimeManager::class),
        app(InstantiateProjectRuntimeDefinitionsAction::class),
        new CommandDeadline,
    );

    $result = $deploy->execute($cloned);

    expect($cloned->status)->toBe(InstanceState::Active)
        ->and($cloned->provisioning_step)->toBe('active')
        ->and($cloned->clone_completed_at)->not->toBeNull()
        ->and($cloned->routes)->toBeEmpty()
        ->and($writer->context?->nodeId)->toBe($targetNode->id)
        ->and($writer->context?->path)->toBe($cloned->production_home)
        ->and($writer->context?->executionUser)->toBe($cloned->production_user)
        ->and($writer->contents)
        ->toBe("APP_DEBUG=\"false\"\nAPP_ENV=\"production\"\nAPP_KEY=\"base64:literal-key\"\n")
        ->and($result->succeeded)->toBeTrue();
});

it('renders independent literal fragments without joining them into a Route placeholder', function (): void {
    [$instance] = clone_environment_fixture('literal-fragments');
    $instance->project->update(['type' => 'monorepo']);
    $instance->routes()->delete();
    $context = app(InstanceEnvironmentContextResolver::class)->resolve($instance, true);

    expect(app(InstanceEnvironmentRenderer::class)->render($context, [
        'A' => '{',
        'B' => '{app_instance.domain}',
        'C' => '}',
    ]))->toBe("A=\"{\"\nB=\"{app_instance.domain}\"\nC=\"}\"\n");
});

it('refuses a Route placeholder without a route before writing', function (): void {
    [$instance] = clone_environment_fixture('missing-domain');
    $instance->project->update(['type' => 'monorepo']);
    $instance->routes()->delete();
    $instance->environmentValues()->create([
        'env_key' => 'APP_URL',
        'env_value' => 'https://{{instance.domain}}',
    ]);
    [$lock, $preflight, $writer] = bind_clone_environment_fakes();
    $synchronizer = new SynchronizeInstanceEnvironmentAction(
        $lock,
        app(InstanceEnvironmentContextResolver::class),
        app(InstanceEnvironmentStore::class),
        $preflight,
        app(InstanceEnvironmentRenderer::class),
        $writer,
    );

    expect(fn () => $synchronizer->execute($instance))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('env.reference_unavailable'));
    expect($writer->contents)->toBeNull();
});

it('supports a clone with no stored environment rows', function (): void {
    [$source, $target] = clone_environment_fixture();
    [, $preflight, $writer] = bind_clone_environment_fakes(changed: false);

    $result = app(CloneInstanceEnvironmentAction::class)->execute($source, $target);

    expect($result->toArray())
        ->toBe([
            'instance_id' => $target->id,
            'operation' => 'clone',
            'changed' => false,
            'key_count' => 2,
        ])
        ->and($target->environmentValues()->pluck('env_value', 'env_key')->all())
        ->toBe([
            'APP_DEBUG' => 'false',
            'APP_ENV' => 'production',
        ])
        ->and($writer->contents)
        ->toBe("APP_DEBUG=\"false\"\nAPP_ENV=\"production\"\n")
        ->and($preflight->requiredCapacityBytes)
        ->toBeGreaterThan(InstanceEnvironmentValidator::MaximumFileBytes);
});

it('preserves target environment edits on retry', function (): void {
    [$source, $target] = clone_environment_fixture();
    $source->environmentValues()->createMany([
        ['env_key' => 'APP_KEY', 'env_value' => 'source-key'],
        ['env_key' => 'SOURCE_ONLY', 'env_value' => 'source-value'],
    ]);
    $target->environmentValues()->create([
        'env_key' => 'APP_KEY',
        'env_value' => 'edited-target-key',
    ]);
    [, , $writer] = bind_clone_environment_fakes();

    $result = app(CloneInstanceEnvironmentAction::class)->execute($source, $target);

    expect($result->keyCount)
        ->toBe(3)
        ->and($target->environmentValues()->orderBy('env_key')->pluck('env_value', 'env_key')->all())
        ->toBe([
            'APP_DEBUG' => 'false',
            'APP_ENV' => 'production',
            'APP_KEY' => 'edited-target-key',
        ])
        ->and($source->environmentValues()->orderBy('env_key')->pluck('env_value', 'env_key')->all())
        ->toBe(['APP_KEY' => 'source-key', 'SOURCE_ONLY' => 'source-value'])
        ->and($writer->contents)
        ->toBe("APP_DEBUG=\"false\"\nAPP_ENV=\"production\"\nAPP_KEY=\"edited-target-key\"\n");
});

it('refuses a stale pending Route or target placement before copying values', function (Closure $change): void {
    [$source, $target, $route] = clone_environment_fixture();
    $source->environmentValues()->create([
        'env_key' => 'APP_KEY',
        'env_value' => 'must-not-copy',
    ]);
    $sourceContext = app(InstanceEnvironmentContextResolver::class)->resolve($source, true);
    $targetContext = app(InstanceEnvironmentContextResolver::class)->resolveForClone($target, true);
    $change($target, $route);

    expect(fn () => app(InstanceEnvironmentStore::class)->copyForClone($sourceContext, $targetContext))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('env.owner_changed');
        });

    expect(InstanceEnvironmentValue::query()->where('instance_id', $target->id)->count())->toBe(0);
})->with([
    'pending Route activates' => [static fn (Instance $target, Route $route) => $route->update([
        'status' => RouteStatus::Active,
    ])],
    'pending Route domain changes' => [static function (Instance $target, Route $route): void {
        $replacement = Route::query()->create([
            'project_id' => $route->project_id,
            'node_id' => $route->node_id,
            'cluster_id' => $route->cluster_id,
            'domain' => 'changed.prod.orbit',
            'provenance' => $route->provenance,
            'publication' => $route->publication,
            'status' => RouteStatus::Pending,
            'replaces_route_id' => $route->id,
            'replacement_step' => RouteReplacementStep::Reserved,
        ]);
        $route->targets()->delete();
        $replacement->targets()->create([
            'instance_id' => $target->id,
            'position' => 0,
        ]);
        $route->delete();
        $replacement->update([
            'replaces_route_id' => null,
            'replacement_step' => null,
        ]);
    }],
    'target placement changes' => [static fn (Instance $target, Route $route) => $target->update([
        'production_home' => '/home/orbit-clone-target-moved',
        'checkout_path' => '/home/orbit-clone-target-moved',
    ])],
]);

it('refuses copying when the target clone candidate no longer owns the operation', function (): void {
    [$source, $target] = clone_environment_fixture();
    [$otherSource] = clone_environment_fixture('other');
    $source->environmentValues()->create([
        'env_key' => 'APP_KEY',
        'env_value' => 'must-not-copy',
    ]);
    $target->update(['clone_candidate_id' => $otherSource->id]);
    bind_clone_environment_fakes();

    expect(fn () => app(CloneInstanceEnvironmentAction::class)->execute($source, $target))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('env.owner_changed');
        });

    expect(InstanceEnvironmentValue::query()->where('instance_id', $target->id)->count())->toBe(0);
});

/** @return array{Instance, Instance, Route} */
function clone_environment_fixture(string $suffix = 'primary'): array
{
    $count = Node::query()->count();
    $previewHostname = $suffix === 'primary' ? 'preview.prod.orbit' : "preview-{$suffix}.prod.orbit";
    $project = Project::query()->create([
        'name' => "Clone environment {$suffix}",
        'slug' => "clone-environment-{$suffix}-{$count}",
        'repository_url' => "https://example.test/clone-environment-{$suffix}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $sourceNode = clone_environment_node("source-{$suffix}", $count + 10);
    $targetNode = clone_environment_node("target-{$suffix}", $count + 11);
    $source = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $sourceNode->id,
        'name' => 'candidate',
        'environment' => 'development',
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => "/srv/orbit/apps/clone-environment/{$suffix}",
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $sourceRoute = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $sourceNode->id,
        'domain' => "source-{$suffix}.example.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $sourceRoute->targets()->create(['instance_id' => $source->id, 'position' => 0]);
    $sourceRoute->update(['status' => RouteStatus::Active]);
    $target = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $targetNode->id,
        'name' => 'target',
        'environment' => 'production',
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/home/orbit-clone-target/releases/initial',
        'production_user' => 'orbit-clone-target',
        'production_home' => '/home/orbit-clone-target',
        'source_is_laravel' => true,
        'provisioning_step' => 'clone-source',
        'clone_candidate_id' => $source->id,
        'clone_candidate_commit' => str_repeat('a', 40),
        'clone_preview_name' => 'preview',
        'clone_preview_domain' => $previewHostname,
        'status' => InstanceState::SourceResolved,
    ]);
    $targetRoute = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $targetNode->id,
        'domain' => $previewHostname,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $targetRoute->targets()->create(['instance_id' => $target->id, 'position' => 0]);

    return [$source->fresh(), $target->fresh(), $targetRoute->fresh()];
}

function clone_environment_node(string $name, int $address): Node
{
    $node = Node::query()->create([
        'name' => "clone-environment-{$name}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$address}",
        'wireguard_ip' => "10.44.0.{$address}",
        'user' => 'orbit',
    ]);
    $node->roles()->create([
        'role' => str_starts_with($name, 'target-') ? 'app-prod' : 'app-dev',
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

/** @return array{Orb198CloneEnvironmentLock, Orb198CloneEnvironmentPreflight, Orb198CloneEnvironmentWriter} */
function bind_clone_environment_fakes(bool $changed = true): array
{
    $lock = new Orb198CloneEnvironmentLock;
    $preflight = new Orb198CloneEnvironmentPreflight;
    $writer = new Orb198CloneEnvironmentWriter($changed);
    app()->instance(InstanceEnvironmentOperationLock::class, $lock);
    app()->instance(InstanceOperationPreflight::class, $preflight);
    app()->instance(InstanceEnvironmentWriter::class, $writer);
    app()->instance(InstanceEnvironmentReader::class, $writer);

    return [$lock, $preflight, $writer];
}

final class Orb198CloneEnvironmentLock implements InstanceEnvironmentOperationLock
{
    /** @var list<int> */
    public array $instanceIds = [];

    public function run(array $instanceIds, Closure $operation): mixed
    {
        $this->instanceIds = $instanceIds;

        return $operation();
    }
}

final class Orb198CloneEnvironmentPreflight implements InstanceOperationPreflight
{
    public ?InstanceEnvironmentContext $context = null;

    public ?int $requiredCapacityBytes = null;

    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void {}

    public function assertEnvironmentWritable(
        InstanceEnvironmentContext $context,
        int $requiredCapacityBytes,
    ): void {
        $this->context = $context;
        $this->requiredCapacityBytes = $requiredCapacityBytes;
    }
}

final class Orb198CloneEnvironmentWriter implements InstanceEnvironmentReader, InstanceEnvironmentWriter
{
    public ?InstanceEnvironmentContext $context = null;

    public ?string $contents = null;

    public function __construct(private readonly bool $changed) {}

    public function read(InstanceEnvironmentContext $context): string
    {
        return $this->contents ?? '';
    }

    public function write(
        InstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
    ): InstanceEnvironmentWriteResult {
        $this->context = $context;
        $this->contents = $contents;

        return $this->changed
            ? InstanceEnvironmentWriteResult::changed()
            : InstanceEnvironmentWriteResult::unchanged();
    }
}
