<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;

/**
 * Writes a complete agent view for one Node, as the subscriber does after a snapshot.
 *
 * @param  array<string, string>  $units  Status keyed `{runtime}:{name}`.
 */
function seed_agent_view(int $nodeId, array $units, string $docker = 'available', float $ageSeconds = 0.0): void
{
    app(CacheAgentStateView::class)->putNode(
        $nodeId,
        $units,
        $docker,
        3,
        CacheAgentStateView::now() - $ageSeconds,
        '2026-09-25T10:00:00Z',
    );
}

function agent_view_node(string $name = 'app-dev', string $address = '10.44.0.3'): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => $address,
    ]);
}

function agent_view_instance_process(Node $node, string $name, ProcessRuntime $runtime = ProcessRuntime::Systemd): Process
{
    $app = OrbitApp::query()->firstOrCreate(['slug' => 'docs'], ['name' => 'Docs', 'repository_url' => 'git@example.test:docs.git']);
    $instance = AppInstance::query()->firstOrCreate(['app_id' => $app->id, 'name' => 'main'], [
        'node_id' => $node->id,
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);

    return Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $instance->id,
        'name' => $name,
        'runtime' => $runtime,
        'working_directory' => '/home/orbit/apps/docs',
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => 'always',
        'desired_state' => 'running',
        'status' => LifecycleStatus::Active,
    ]);
}
