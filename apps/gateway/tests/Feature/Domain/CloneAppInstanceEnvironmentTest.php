<?php

declare(strict_types=1);

use App\Actions\AppInstances\CloneAppInstanceEnvironmentAction;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentValidator;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriter;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriteResult;
use App\Domain\AppInstances\Environment\AppInstanceOperationPreflight;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

it('copies independent encrypted values and resolves placeholders through the exact pending clone Route', function (): void {
    [$source, $target] = clone_environment_fixture();
    $source->environmentValues()->createMany([
        ['env_key' => 'APP_ENV', 'env_value' => '{{app_instance.environment}}'],
        ['env_key' => 'APP_KEY', 'env_value' => 'base64:literal-key'],
        ['env_key' => 'APP_URL', 'env_value' => 'https://{{app_instance.hostname}}/path'],
    ]);
    $sourceCiphertext = DB::table('app_instance_environment_values')
        ->where('app_instance_id', $source->id)
        ->orderBy('env_key')
        ->pluck('env_value', 'env_key')
        ->all();
    [$lock, $preflight, $writer] = bind_clone_environment_fakes();

    $result = app(CloneAppInstanceEnvironmentAction::class)->execute($source, $target);

    $targetValues = $target->environmentValues()->orderBy('env_key')->pluck('env_value', 'env_key')->all();
    $targetCiphertext = DB::table('app_instance_environment_values')
        ->where('app_instance_id', $target->id)
        ->orderBy('env_key')
        ->pluck('env_value', 'env_key')
        ->all();
    expect($result->toArray())
        ->toBe([
            'app_instance_id' => $target->id,
            'operation' => 'clone',
            'changed' => true,
            'key_count' => 3,
        ])
        ->and($targetValues)
        ->toBe([
            'APP_ENV' => '{{app_instance.environment}}',
            'APP_KEY' => 'base64:literal-key',
            'APP_URL' => 'https://{{app_instance.hostname}}/path',
        ])
        ->and(array_keys($targetCiphertext))
        ->toBe(array_keys($sourceCiphertext));
    foreach ($sourceCiphertext as $key => $ciphertext) {
        expect($targetCiphertext[$key])->not->toBe($ciphertext);
    }
    expect($writer->contents)
        ->toBe("APP_ENV=\"production\"\n"
            ."APP_KEY=\"base64:literal-key\"\n"
            ."APP_URL=\"https://preview.prod.orbit/path\"\n")
        ->and($writer->context?->routeHostname)
        ->toBe('preview.prod.orbit')
        ->and($writer->context?->routeId)
        ->toBe($target->routes()->sole()->id)
        ->and($lock->appInstanceIds)
        ->toBe([$source->id, $target->id])
        ->and($preflight->requiredCapacityBytes)
        ->toBeGreaterThan(AppInstanceEnvironmentValidator::MaximumFileBytes);
});

it('supports a clone with no stored environment rows', function (): void {
    [$source, $target] = clone_environment_fixture();
    [, $preflight, $writer] = bind_clone_environment_fakes(changed: false);

    $result = app(CloneAppInstanceEnvironmentAction::class)->execute($source, $target);

    expect($result->toArray())
        ->toBe([
            'app_instance_id' => $target->id,
            'operation' => 'clone',
            'changed' => false,
            'key_count' => 0,
        ])
        ->and($target->environmentValues()->count())
        ->toBe(0)
        ->and($writer->contents)
        ->toBe('')
        ->and($preflight->requiredCapacityBytes)
        ->toBe(AppInstanceEnvironmentValidator::MaximumFileBytes);
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

    $result = app(CloneAppInstanceEnvironmentAction::class)->execute($source, $target);

    expect($result->keyCount)
        ->toBe(1)
        ->and($target->environmentValues()->pluck('env_value', 'env_key')->all())
        ->toBe(['APP_KEY' => 'edited-target-key'])
        ->and($source->environmentValues()->orderBy('env_key')->pluck('env_value', 'env_key')->all())
        ->toBe(['APP_KEY' => 'source-key', 'SOURCE_ONLY' => 'source-value'])
        ->and($writer->contents)
        ->toBe("APP_KEY=\"edited-target-key\"\n");
});

it('refuses a stale pending Route or target placement before copying values', function (Closure $change): void {
    [$source, $target, $route] = clone_environment_fixture();
    $source->environmentValues()->create([
        'env_key' => 'APP_KEY',
        'env_value' => 'must-not-copy',
    ]);
    $sourceContext = app(AppInstanceEnvironmentContextResolver::class)->resolve($source, true);
    $targetContext = app(AppInstanceEnvironmentContextResolver::class)->resolveForClone($target, true);
    $change($target, $route);

    expect(fn () => app(AppInstanceEnvironmentStore::class)->copyForClone($sourceContext, $targetContext))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('env.owner_changed');
        });

    expect(AppInstanceEnvironmentValue::query()->where('app_instance_id', $target->id)->count())->toBe(0);
})->with([
    'pending Route activates' => [static fn (AppInstance $target, Route $route) => $route->update([
        'status' => RouteStatus::Active,
    ])],
    'pending Route hostname changes' => [static fn (AppInstance $target, Route $route) => $route->update([
        'hostname' => 'changed.prod.orbit',
    ])],
    'target placement changes' => [static fn (AppInstance $target, Route $route) => $target->update([
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

    expect(fn () => app(CloneAppInstanceEnvironmentAction::class)->execute($source, $target))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('env.owner_changed');
        });

    expect(AppInstanceEnvironmentValue::query()->where('app_instance_id', $target->id)->count())->toBe(0);
});

/** @return array{AppInstance, AppInstance, Route} */
function clone_environment_fixture(string $suffix = 'primary'): array
{
    $count = Node::query()->count();
    $previewHostname = $suffix === 'primary' ? 'preview.prod.orbit' : "preview-{$suffix}.prod.orbit";
    $app = OrbitApp::query()->create([
        'name' => "Clone environment {$suffix}",
        'slug' => "clone-environment-{$suffix}-{$count}",
        'repository_url' => "https://example.test/clone-environment-{$suffix}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $sourceNode = clone_environment_node("source-{$suffix}", $count + 10);
    $targetNode = clone_environment_node("target-{$suffix}", $count + 11);
    $source = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $sourceNode->id,
        'name' => 'candidate',
        'environment' => 'development',
        'source_layout' => AppInstanceSourceLayout::Checkout,
        'checkout_path' => "/srv/orbit/apps/clone-environment/{$suffix}",
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $sourceRoute = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $sourceNode->id,
        'hostname' => "source-{$suffix}.example.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $sourceRoute->targets()->create(['app_instance_id' => $source->id, 'position' => 0]);
    $sourceRoute->update(['status' => RouteStatus::Active]);
    $target = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $targetNode->id,
        'name' => 'target',
        'environment' => 'production',
        'source_layout' => AppInstanceSourceLayout::Checkout,
        'checkout_path' => '/home/orbit-clone-target/releases/initial',
        'production_user' => 'orbit-clone-target',
        'production_home' => '/home/orbit-clone-target',
        'source_is_laravel' => true,
        'provisioning_step' => 'clone-source',
        'clone_candidate_id' => $source->id,
        'clone_candidate_commit' => str_repeat('a', 40),
        'clone_preview_name' => 'preview',
        'clone_preview_hostname' => $previewHostname,
        'status' => AppInstanceState::SourceResolved,
    ]);
    $targetRoute = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $targetNode->id,
        'hostname' => $previewHostname,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $targetRoute->targets()->create(['app_instance_id' => $target->id, 'position' => 0]);

    return [$source->fresh(), $target->fresh(), $targetRoute->fresh()];
}

function clone_environment_node(string $name, int $address): Node
{
    return Node::query()->create([
        'name' => "clone-environment-{$name}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$address}",
        'wireguard_ip' => "10.44.0.{$address}",
        'user' => 'orbit',
    ]);
}

/** @return array{Orb198CloneEnvironmentLock, Orb198CloneEnvironmentPreflight, Orb198CloneEnvironmentWriter} */
function bind_clone_environment_fakes(bool $changed = true): array
{
    $lock = new Orb198CloneEnvironmentLock;
    $preflight = new Orb198CloneEnvironmentPreflight;
    $writer = new Orb198CloneEnvironmentWriter($changed);
    app()->instance(AppInstanceEnvironmentOperationLock::class, $lock);
    app()->instance(AppInstanceOperationPreflight::class, $preflight);
    app()->instance(AppInstanceEnvironmentWriter::class, $writer);

    return [$lock, $preflight, $writer];
}

final class Orb198CloneEnvironmentLock implements AppInstanceEnvironmentOperationLock
{
    /** @var list<int> */
    public array $appInstanceIds = [];

    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        $this->appInstanceIds = $appInstanceIds;

        return $operation();
    }
}

final class Orb198CloneEnvironmentPreflight implements AppInstanceOperationPreflight
{
    public ?AppInstanceEnvironmentContext $context = null;

    public ?int $requiredCapacityBytes = null;

    public function assertEnvironmentReadable(AppInstanceEnvironmentContext $context): void {}

    public function assertEnvironmentWritable(
        AppInstanceEnvironmentContext $context,
        int $requiredCapacityBytes,
    ): void {
        $this->context = $context;
        $this->requiredCapacityBytes = $requiredCapacityBytes;
    }
}

final class Orb198CloneEnvironmentWriter implements AppInstanceEnvironmentWriter
{
    public ?AppInstanceEnvironmentContext $context = null;

    public ?string $contents = null;

    public function __construct(private readonly bool $changed) {}

    public function write(
        AppInstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
    ): AppInstanceEnvironmentWriteResult {
        $this->context = $context;
        $this->contents = $contents;

        return $this->changed
            ? AppInstanceEnvironmentWriteResult::changed()
            : AppInstanceEnvironmentWriteResult::unchanged();
    }
}
