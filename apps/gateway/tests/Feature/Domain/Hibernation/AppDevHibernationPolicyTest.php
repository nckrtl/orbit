<?php

declare(strict_types=1);

use App\Domain\Hibernation\AppDevHibernationPolicy;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;

it('applies only to development AppInstance Processes on an active app-dev Node', function (): void {
    $policy = new AppDevHibernationPolicy;
    $appDev = hibernation_policy_node('app-dev', 'app-dev');
    $appProd = hibernation_policy_node('app-prod', 'app-prod');
    $app = OrbitApp::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
    ]);
    $development = hibernation_policy_instance($app, $appDev, 'development');
    $production = hibernation_policy_instance($app, $appProd, 'production');
    $eligible = hibernation_policy_process($development);
    $nodeOwned = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $appDev->id,
        'name' => 'postgres',
        'runtime' => 'docker',
        'working_directory' => '/app',
        'runtime_config' => ['image' => 'postgres:18', 'command' => ['postgres']],
        'restart_policy' => 'always',
        'desired_state' => 'running',
        'status' => 'active',
    ]);

    expect($policy->appliesToInstance($development))
        ->toBeTrue()
        ->and($policy->appliesToProcess($eligible))
        ->toBeTrue()
        ->and($policy->appliesToInstance($production))
        ->toBeFalse()
        ->and($policy->appliesToProcess($nodeOwned))
        ->toBeFalse()
        ->and($policy->usesOnDemandHostStart($development))
        ->toBeTrue()
        ->and($policy->usesOnDemandHostStart($production))
        ->toBeFalse();
});

it('does not treat restart policy as an exemption', function (): void {
    $policy = new AppDevHibernationPolicy;
    $node = hibernation_policy_node('app-dev', 'app-dev');
    $app = OrbitApp::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
    ]);
    $instance = hibernation_policy_instance($app, $node, 'development');
    $always = hibernation_policy_process($instance, 'always');

    expect($policy->appliesToProcess($always))->toBeTrue();
});

function hibernation_policy_node(string $name, string $role): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => $name === 'app-dev' ? '10.44.0.3' : '10.44.0.4',
    ]);
    $node->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active]);

    return $node;
}

function hibernation_policy_instance(OrbitApp $app, Node $node, string $environment): AppInstance
{
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $environment,
        'environment' => $environment,
        'checkout_path' => $environment === 'development' ? '/home/orbit/apps/docs' : '/var/www/docs',
        'production_user' => $environment === 'production' ? 'orbit-docs' : null,
        'production_home' => $environment === 'production' ? '/var/www/docs' : null,
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
}

function hibernation_policy_process(AppInstance $instance, string $restartPolicy = 'on-failure'): Process
{
    return Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $instance->id,
        'name' => 'vite-'.$restartPolicy,
        'runtime' => 'systemd',
        'working_directory' => $instance->checkout_path,
        'runtime_config' => ['command' => ['/usr/bin/vp', 'run', 'dev']],
        'restart_policy' => $restartPolicy,
        'desired_state' => 'running',
        'status' => 'active',
    ]);
}
