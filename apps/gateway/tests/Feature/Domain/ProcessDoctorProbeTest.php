<?php

declare(strict_types=1);

use App\Actions\Doctor\ProcessDoctorProbe;
use App\Data\Doctor\DoctorFamilyReportData;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\ProcessInspectionData;
use App\Domain\Doctor\ProcessInspectionStatus;
use App\Domain\Doctor\ProcessStateInspector;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;

it('returns a healthy empty report without runtime inspection when the node has no processes', function (): void {
    $node = doctor_process_node();
    $runtime = Mockery::mock(ProcessStateInspector::class);
    $runtime->shouldNotReceive('inspect');

    $report = new ProcessDoctorProbe($runtime)->inspect(doctor_process_context($node));

    expect($report->checked)
        ->toBe(0)
        ->and($report->issues)
        ->toBeEmpty();
});

it('compares selected process runtimes in process id order', function (): void {
    $node = Node::query()->create([
        'name' => 'doctor-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_address' => '10.44.0.3',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'App',
        'slug' => 'app',
        'repository_url' => 'git@example.test:app.git',
    ]);
    $instance = Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/app',
        'hostname' => 'app.test',
        'certificate_mode' => 'orbit-ca',
        'status' => LifecycleStatus::Active,
    ]);
    $first = Process::query()->create([
        'owner_type' => Instance::class,
        'owner_id' => $instance->id,
        'name' => 'first',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/tmp',
        'runtime_config' => [],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
    $second = $first->replicate();
    $second->name = 'second';
    $second->desired_state = DesiredProcessState::Stopped;
    $second->save();

    $runtime = Mockery::mock(ProcessStateInspector::class);
    $runtime
        ->shouldReceive('inspect')
        ->once()
        ->with(Mockery::on(fn (Process $process): bool => $process->is($first)))
        ->andReturn(new ProcessInspectionData(true, ProcessInspectionStatus::Inactive));
    $runtime
        ->shouldReceive('inspect')
        ->once()
        ->with(Mockery::on(fn (Process $process): bool => $process->is($second)))
        ->andReturn(new ProcessInspectionData(true, ProcessInspectionStatus::Active));

    $report = new ProcessDoctorProbe($runtime)->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)),
    );

    expect($report)
        ->toBeInstanceOf(DoctorFamilyReportData::class)
        ->and($report->checked)
        ->toBe(2)
        ->and($report->issues)
        ->toHaveCount(2)
        ->and(collect($report->issues)->pluck('resourceId')->all())
        ->toBe([$first->id, $second->id]);
});

it('does not inspect runtimes when the node is unreachable', function (): void {
    $node = Node::query()->create([
        'name' => 'unreachable',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.21',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_address' => '10.44.0.4',
    ]);
    doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Running, name: 'unreachable-process');

    $runtime = Mockery::mock(ProcessStateInspector::class);
    $runtime->shouldNotReceive('inspect');
    $report = new ProcessDoctorProbe($runtime)->inspect(
        new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null)),
    );

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('process.node_unreachable')
        ->and($report->issues[0]->kind->value)
        ->toBe('unverifiable')
        ->and($report->issues[0]->resourceId)
        ->toBeNull()
        ->and($report->issues[0]->resourceName)
        ->toBeNull();
});

it('supports all bounded healthy runtime states', function (
    ProcessRuntime $runtime,
    DesiredProcessState $desired,
    ProcessInspectionStatus $observed,
): void {
    $node = doctor_process_node();
    $process = doctor_process($node, $runtime, $desired);
    $manager = Mockery::mock(ProcessStateInspector::class);
    $manager
        ->shouldReceive('inspect')
        ->once()
        ->with(Mockery::on(fn (Process $value): bool => $value->is($process)))
        ->andReturn(new ProcessInspectionData(true, $observed));

    $report = new ProcessDoctorProbe($manager)->inspect(doctor_process_context($node));

    expect($report->checked)->toBe(1)->and($report->issues)->toBeEmpty();
})->with([
    [ProcessRuntime::Systemd, DesiredProcessState::Running, ProcessInspectionStatus::Active],
    [ProcessRuntime::Docker,  DesiredProcessState::Running, ProcessInspectionStatus::Running],
    [ProcessRuntime::Systemd, DesiredProcessState::Stopped, ProcessInspectionStatus::Inactive],
    [ProcessRuntime::Docker,  DesiredProcessState::Stopped, ProcessInspectionStatus::Created],
    [ProcessRuntime::Docker,  DesiredProcessState::Stopped, ProcessInspectionStatus::Exited],
]);

it('continues after bounded runtime failures and redacts observations', function (): void {
    $node = doctor_process_node();
    $failed = doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Running, name: 'failed');
    $mismatch = doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Running, name: 'mismatch');
    $manager = Mockery::mock(ProcessStateInspector::class);
    $manager
        ->shouldReceive('inspect')
        ->once()
        ->with(Mockery::on(fn (Process $value): bool => $value->is($failed)))
        ->andThrow(new DoctorInspectionException);
    $manager
        ->shouldReceive('inspect')
        ->once()
        ->with(Mockery::on(fn (Process $value): bool => $value->is($mismatch)))
        ->andThrow(new DoctorInspectionException);

    $report = new ProcessDoctorProbe($manager)->inspect(doctor_process_context($node));
    $issues = collect($report->issues);

    expect($report->checked)
        ->toBe(2)
        ->and($issues->pluck('code')->all())
        ->toBe(['process.inspection_failed', 'process.inspection_failed'])
        ->and($issues->pluck('observed')->all())
        ->toBe([null, null])
        ->and(json_encode($report))
        ->not->toContain('secret');
});

it('bounds unknown status and reports absent runtime', function (): void {
    $node = doctor_process_node();
    $unknown = doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Running, name: 'unknown');
    $absent = doctor_process($node, ProcessRuntime::Docker, DesiredProcessState::Running, name: 'absent');
    $manager = Mockery::mock(ProcessStateInspector::class);
    $manager
        ->shouldReceive('inspect')
        ->twice()
        ->andReturn(
            new ProcessInspectionData(true, ProcessInspectionStatus::Other),
            new ProcessInspectionData(false, null),
        );

    $report = new ProcessDoctorProbe($manager)->inspect(doctor_process_context($node));

    expect(collect($report->issues)->pluck('code')->all())
        ->toBe(['process.state_mismatch', 'process.runtime_missing'])
        ->and($report->issues[0]->observed)
        ->toBe('other')
        ->and($report->issues[1]->observed)
        ->toBe('absent');
});

it('selects workspace processes only through the exact effective node morph', function (): void {
    $node = doctor_process_node();
    $other = doctor_process_node();
    $app = OrbitApp::query()->create([
        'name' => 'Workspace App',
        'slug' => fake()->unique()->slug(),
        'repository_url' => 'git@example.test:workspace.git',
    ]);
    $instance = Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'instance',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/app',
        'hostname' => "workspace-instance-{$node->id}.test",
        'certificate_mode' => 'orbit-ca',
        'status' => LifecycleStatus::Active,
    ]);
    $otherInstance = $instance->replicate();
    $otherInstance->node_id = $other->id;
    $otherInstance->hostname = "workspace-other-{$other->id}.test";
    $otherInstance->save();
    $workspace = Workspace::query()->create([
        'instance_id' => $instance->id,
        'name' => 'workspace',
        'branch' => 'main',
        'checkout_path' => '/tmp/workspace',
        'hostname' => fake()->unique()->domainName(),
        'status' => LifecycleStatus::Active,
    ]);
    $otherWorkspace = Workspace::query()->create([
        'instance_id' => $otherInstance->id,
        'name' => 'other',
        'branch' => 'main',
        'checkout_path' => '/tmp/other',
        'hostname' => fake()->unique()->domainName(),
        'status' => LifecycleStatus::Active,
    ]);
    $selected = doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Running, name: 'selected');
    $selected->owner_type = Workspace::class;
    $selected->owner_id = $workspace->id;
    $selected->save();
    $excluded = $selected->replicate();
    $excluded->owner_id = $otherWorkspace->id;
    $excluded->save();
    $wrongMorph = $selected->replicate();
    $wrongMorph->owner_type = 'App\\Models\\App';
    $wrongMorph->save();
    $manager = Mockery::mock(ProcessStateInspector::class);
    $manager
        ->shouldReceive('inspect')
        ->once()
        ->with(Mockery::on(fn (Process $process): bool => $process->is($selected)))
        ->andReturn(new ProcessInspectionData(true, ProcessInspectionStatus::Active));

    $report = new ProcessDoctorProbe($manager)->inspect(doctor_process_context($node));

    expect($report->checked)->toBe(1)->and($report->issues)->toBeEmpty();
});

function doctor_process_node(): Node
{
    static $address = 30;
    $address++;

    return Node::query()->create([
        'name' => fake()->unique()->word(),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$address}",
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_address' => "10.44.0.{$address}",
    ]);
}

function doctor_process_context(Node $node): DoctorNodeContext
{
    return new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true));
}

function doctor_process(
    Node $node,
    ProcessRuntime $runtime,
    DesiredProcessState $desired,
    string $name = 'process',
): Process {
    $app = OrbitApp::query()->create([
        'name' => fake()->word(),
        'slug' => fake()->unique()->slug(),
        'repository_url' => 'git@example.test:app.git',
    ]);
    $instance = Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => fake()->word(),
        'environment' => 'development',
        'checkout_path' => '/home/orbit/app',
        'hostname' => "process-{$node->id}-{$name}.test",
        'certificate_mode' => 'orbit-ca',
        'status' => LifecycleStatus::Active,
    ]);

    return Process::query()->create([
        'owner_type' => Instance::class,
        'owner_id' => $instance->id,
        'name' => $name,
        'runtime' => $runtime,
        'working_directory' => '/tmp',
        'runtime_config' => ['opaque' => 'hidden'],
        'restart_policy' => 'always',
        'desired_state' => $desired,
        'status' => LifecycleStatus::Active,
    ]);
}
