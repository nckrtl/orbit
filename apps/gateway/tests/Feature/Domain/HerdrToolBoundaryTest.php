<?php

declare(strict_types=1);

use App\Actions\Tools\UpdateToolAction;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolNodeEligibility;
use App\Domain\Tools\ToolStatus;
use App\Domain\Tools\VersionConstraint;
use App\Models\HerdrSession;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Tests\Support\FakeToolManager;
use Tests\Support\ImmediateToolOperationLock;
use Tests\Support\ProcessesApiFakeRuntimeManager;

it('does not restart a managed Herdr session when the Herdr Tool is updated', function (): void {
    $runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $runtime);

    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.8',
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43),
    ]);
    $managerRecord = ToolManagerRecord::query()->create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Brew->value,
        'status' => LifecycleStatus::Active,
    ]);
    $tool = Tool::query()->create([
        'node_id' => $node->id,
        'tool_manager_id' => $managerRecord->id,
        'package' => 'herdr',
        'status' => ToolStatus::Installed,
        'installed_version' => '0.8.2',
    ]);
    $process = $node->processes()->create([
        'name' => 'herdr-commander-tasks',
        'runtime' => 'systemd',
        'working_directory' => '/home/nckrtl',
        'runtime_config' => ['command' => ['herdr'], 'environment_file' => ''],
        'restart_policy' => 'unless-stopped',
        'desired_state' => 'running',
        'status' => LifecycleStatus::Active,
    ]);
    HerdrSession::query()->create([
        'node_id' => $node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'process_id' => $process->id,
        'observer_port' => 7411,
        'observer_hostname' => 'commander-tasks.herdr.beast.orbit',
        'observer_status' => 'published',
        'status' => LifecycleStatus::Active,
        'herdr_version' => '0.8.2',
        'protocol' => 22,
        'publish_observer' => true,
    ]);

    $manager = new FakeToolManager(ToolManagerName::Brew);
    $manager->installedVersions = ['0.8.2', '0.9.0'];

    new UpdateToolAction(
        managers: new ToolManagerRegistry([$manager]),
        constraints: new VersionConstraint,
        lock: new ImmediateToolOperationLock,
        eligibility: new ToolNodeEligibility,
    )->execute($tool);

    expect($runtime->convergedProcessIds)
        ->toBeEmpty()
        ->and($runtime->started)
        ->toBeEmpty()
        ->and(HerdrSession::query()->sole()->process_id)
        ->toBe($process->id)
        ->and($process->refresh()->desired_state->value)
        ->toBe('running');
});
