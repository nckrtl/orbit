<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;

it('derives development placement from the AppInstance', function (): void {
    $instance = process_target_instance();

    $target = app(ProcessTargetResolver::class)->resolve(ProcessTargetType::AppInstance, $instance->id);

    expect($target->appInstance?->is($instance))
        ->toBeTrue()
        ->and($target->node->is($instance->node))
        ->toBeTrue()
        ->and($target->user)
        ->toBe('orbit')
        ->and($target->defaultWorkingDirectory)
        ->toBe('/srv/orbit/docs/main')
        ->and($target->environmentFile)
        ->toBe('/srv/orbit/docs/main/.env')
        ->and($target->certificateScope)
        ->toBe("app-instance-{$instance->id}")
        ->and($target->productionReleaseLayout)
        ->toBeFalse()
        ->and($target->routeDomain)
        ->toBeNull();
});

it('derives the development-server origin hostname from the AppInstance Route', function (): void {
    $instance = process_target_instance();
    $route = Route::query()->create([
        'app_id' => $instance->app_id,
        'node_id' => $instance->node_id,
        'domain' => 'tasks.commander.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    $target = app(ProcessTargetResolver::class)->resolve(ProcessTargetType::AppInstance, $instance->id);

    expect($target->routeDomain)->toBe('tasks.commander.test');
});

it('derives production placement from the dedicated identity and current release', function (): void {
    $instance = process_target_instance('production', [
        'checkout_path' => '/home/orbit-docs/releases/20260910',
        'production_user' => 'orbit-docs',
        'production_home' => '/home/orbit-docs',
    ]);
    $instance->node->roles()->create(['role' => 'app-prod', 'status' => LifecycleStatus::Active]);

    $target = app(ProcessTargetResolver::class)->forStart(process_target_process($instance));

    expect($target->node->is($instance->node))
        ->toBeTrue()
        ->and($target->user)
        ->toBe('orbit-docs')
        ->and($target->defaultWorkingDirectory)
        ->toBe('/home/orbit-docs/current')
        ->and($target->environmentFile)
        ->toBe('/home/orbit-docs/.env')
        ->and($target->certificateScope)
        ->toBeNull()
        ->and($target->productionReleaseLayout)
        ->toBeTrue();
});

it('rejects inactive AppInstances and Nodes for admission', function (array $instanceChanges, array $nodeChanges): void {
    $instance = process_target_instance();
    $instance->update($instanceChanges);
    $instance->node->update($nodeChanges);

    app(ProcessTargetResolver::class)->forAdmission($instance->refresh()->load('node'));
})->with([
    'inactive AppInstance' => [['status' => AppInstanceState::SourceResolved], []],
    'unfinished provisioning' => [['provisioning_step' => 'source'], []],
    'migration required' => [['migration_required' => true], []],
    'inactive Node' => [[], ['status' => LifecycleStatus::Failed]],
])->throws(ResourceOperationException::class, 'not active');

it('allows inspection and cleanup during incomplete removal when the Node remains reachable', function (): void {
    $instance = process_target_instance();
    $instance->update([
        'status' => AppInstanceState::SourceResolved,
        'failed_step' => 'runtime_cleanup',
        'error_code' => 'process.remove_failed',
    ]);
    $process = process_target_process($instance->refresh());
    $resolver = app(ProcessTargetResolver::class);

    expect($resolver->forInspection($process)->appInstance?->id)
        ->toBe($instance->id)
        ->and($resolver->forRemoval($process)->appInstance?->id)
        ->toBe($instance->id);
});

it('allows bounded inspection but refuses cleanup when the target Node is inactive', function (): void {
    $instance = process_target_instance();
    $instance->node->update(['status' => LifecycleStatus::Failed]);
    $process = process_target_process($instance);
    $resolver = app(ProcessTargetResolver::class);

    expect($resolver->forInspection($process)->node->id)->toBe($instance->node_id);

    $resolver->forRemoval($process);
})->throws(ResourceOperationException::class, 'not active');

it('derives Node placement from the managed user home', function (): void {
    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'user' => 'orbit',
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '10.44.0.40',
    ]);

    $target = app(ProcessTargetResolver::class)->resolve(ProcessTargetType::Node, $node->id);

    expect($target->appInstance)
        ->toBeNull()
        ->and($target->node->is($node))
        ->toBeTrue()
        ->and($target->user)
        ->toBe('orbit')
        ->and($target->defaultWorkingDirectory)
        ->toBe('/home/orbit')
        ->and($target->environmentFile)
        ->toBe('')
        ->and($target->certificateScope)
        ->toBeNull()
        ->and($target->productionReleaseLayout)
        ->toBeFalse();
});

it('rejects inactive or unmanaged Nodes for admission', function (array $changes): void {
    $node = Node::query()->create([
        'name' => 'idle',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'user' => 'orbit',
        'public_ssh_host' => '192.0.2.41',
        'wireguard_ip' => '10.44.0.41',
        ...$changes,
    ]);

    app(ProcessTargetResolver::class)->resolve(ProcessTargetType::Node, $node->id);
})->with([
    'inactive Node' => [['status' => LifecycleStatus::Failed]],
    'unmanaged Node' => [['wireguard_ip' => null]],
])->throws(ResourceOperationException::class, 'not active');

it('allows inspection of a Node Process when the Node is inactive', function (): void {
    $node = Node::query()->create([
        'name' => 'inspect-node',
        'status' => LifecycleStatus::Failed,
        'platform' => 'linux',
        'user' => 'orbit',
        'public_ssh_host' => '192.0.2.42',
        'wireguard_ip' => '10.44.0.42',
    ]);
    $process = $node->processes()->create([
        'name' => 'postgres',
        'runtime' => 'docker',
        'working_directory' => '/app',
        'runtime_config' => ['image' => 'postgres:18', 'command' => ['postgres']],
        'restart_policy' => 'unless-stopped',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Active,
    ]);

    expect(app(ProcessTargetResolver::class)->forInspection($process)->node->id)->toBe($node->id);
});

it('rejects a leftover Process owner before target resolution', function (): void {
    $process = Process::query()->create([
        'owner_type' => 'App\\Models\\Instance',
        'owner_id' => 999_999,
        'name' => 'legacy',
        'runtime' => 'systemd',
        'working_directory' => '/srv/legacy',
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => 'never',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Active,
    ]);

    app(ProcessTargetResolver::class)->forInspection($process);
})->throws(ResourceOperationException::class, 'not a supported AppInstance or Node');

function process_target_instance(string $environment = 'development', array $attributes = []): AppInstance
{
    $app = OrbitApp::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'https://example.test/docs.git',
    ]);
    $node = Node::query()->create([
        'name' => "{$environment}-node",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'user' => 'orbit',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main',
        'environment' => $environment,
        'checkout_path' => '/srv/orbit/docs/main',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
        ...$attributes,
    ])->load('node');
}

function process_target_process(AppInstance $instance): Process
{
    return $instance->processes()->create([
        'name' => 'queue',
        'runtime' => 'systemd',
        'working_directory' => $instance->checkout_path,
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => 'never',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Active,
    ]);
}
