<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

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
        ->toBeFalse();
});

it('derives production placement from the dedicated identity and current release', function (): void {
    $instance = process_target_instance('production', [
        'checkout_path' => '/home/orbit-docs/releases/20260910',
        'production_user' => 'orbit-docs',
        'production_home' => '/home/orbit-docs',
    ]);
    $instance->node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);

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

it('rejects a legacy Process owner before target resolution', function (): void {
    $instance = process_target_legacy_instance();
    $process = Process::query()->create([
        'owner_type' => Instance::class,
        'owner_id' => $instance->id,
        'name' => 'legacy',
        'runtime' => 'systemd',
        'working_directory' => $instance->checkout_path,
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => 'never',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Active,
    ]);

    app(ProcessTargetResolver::class)->forInspection($process);
})->throws(ResourceOperationException::class, 'not a supported AppInstance');

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

function process_target_legacy_instance(): Instance
{
    $app = OrbitApp::query()->create([
        'name' => 'Legacy',
        'slug' => 'legacy',
        'repository_url' => 'https://example.test/legacy.git',
    ]);
    $node = Node::query()->create([
        'name' => 'legacy-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.11',
    ]);

    return Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'legacy',
        'environment' => 'development',
        'checkout_path' => '/srv/legacy',
        'hostname' => 'legacy.example.test',
        'certificate_mode' => 'orbit-ca',
        'status' => LifecycleStatus::Active,
    ]);
}
