<?php

declare(strict_types=1);

use App\Actions\Doctor\HerdrSessionDoctorProbe;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\HerdrSessionDoctorIssueCode;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Herdr\HerdrSessionHealth;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\HerdrSession;
use App\Models\Node;

it('reports distinct Process, listener, and session findings', function (): void {
    $runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.8',
    ]);
    $process = $node->processes()->create([
        'name' => 'herdr-commander-tasks',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/home/nckrtl',
        'runtime_config' => ['command' => ['herdr'], 'environment_file' => ''],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Stopped,
        'status' => LifecycleStatus::Active,
    ]);
    HerdrSession::query()->create([
        'node_id' => $node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'process_id' => $process->id,
        'observer_port' => 7411,
        'observer_hostname' => 'commander-tasks.herdr.beast.orbit',
        'observer_status' => 'failed',
        'status' => LifecycleStatus::Failed,
        'publish_observer' => true,
    ]);

    $report = new HerdrSessionDoctorProbe(new HerdrSessionHealth($runtime))
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', '10.44.0.8')));

    expect($report->family)
        ->toBe(DoctorFamily::Herdr)
        ->and($report->checked)
        ->toBe(1)
        ->and(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe([
            HerdrSessionDoctorIssueCode::ProcessUnhealthy->value,
            HerdrSessionDoctorIssueCode::ListenerUnhealthy->value,
            HerdrSessionDoctorIssueCode::SessionUnhealthy->value,
        ]);
});

it('reports unreachable nodes without changing Herdr sessions', function (): void {
    $runtime = new ProcessesApiFakeRuntimeManager;
    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.8',
    ]);
    HerdrSession::query()->create([
        'node_id' => $node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'observer_port' => 7411,
        'observer_hostname' => 'commander-tasks.herdr.beast.orbit',
        'observer_status' => 'published',
        'status' => LifecycleStatus::Active,
        'publish_observer' => true,
    ]);

    $report = new HerdrSessionDoctorProbe(new HerdrSessionHealth($runtime))
        ->inspect(new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null)));

    expect($report->issues[0]->code)
        ->toBe(HerdrSessionDoctorIssueCode::NodeUnreachable->value)
        ->and(HerdrSession::query()->count())
        ->toBe(1);
});
