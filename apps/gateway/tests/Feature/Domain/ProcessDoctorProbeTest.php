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
use App\Domain\Doctor\SystemdProcessObservationData;
use App\Domain\Hibernation\DevelopmentHibernationPolicy;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceState;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Doctor\NativeProcessStateInspector;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

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

it('inspects Node-owned Processes on the selected Node', function (): void {
    $node = doctor_process_node();
    $process = $node->processes()->create([
        'name' => 'postgres',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => ['image' => 'postgres:18', 'command' => ['postgres']],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
    $runtime = Mockery::mock(ProcessStateInspector::class);
    $runtime
        ->shouldReceive('inspect')
        ->once()
        ->with(Mockery::on(fn (Process $inspected): bool => $inspected->is($process)))
        ->andReturn(new ProcessInspectionData(true, ProcessInspectionStatus::Running));

    $report = new ProcessDoctorProbe($runtime)->inspect(doctor_process_context($node));

    expect($report->checked)
        ->toBe(1)
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
        'wireguard_ip' => '10.44.0.3',
    ]);
    $project = Project::query()->create([
        'name' => 'App',
        'slug' => 'app',
        'repository_url' => 'git@example.test:app.git',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/app',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $first = Process::query()->create([
        'owner_type' => Instance::MorphAlias,
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

it('skips Process inspection for an Instance already removing', function (): void {
    $node = doctor_process_node();
    $process = doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Running);
    $instance = Instance::query()->findOrFail($process->owner_id);
    doctor_process_mark_removing($instance);

    $runtime = Mockery::mock(ProcessStateInspector::class);
    $runtime->shouldNotReceive('inspect');

    $report = new ProcessDoctorProbe($runtime)->inspect(doctor_process_context($node));

    expect($report->checked)->toBe(0)->and($report->issues)->toBeEmpty();
});

it('drops removing Instance Process issues after inspection and retains Node-owned issues', function (): void {
    $node = doctor_process_node();
    $owned = doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Running, name: 'owned');
    $instance = Instance::query()->findOrFail($owned->owner_id);
    $nodeOwned = $node->processes()->create([
        'name' => 'node-owned',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/tmp',
        'runtime_config' => [],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
    $runtime = new class($instance) implements ProcessStateInspector
    {
        public function __construct(private Instance $instance) {}

        public function inspect(Process $process): ProcessInspectionData
        {
            if ($process->owner_type === Instance::MorphAlias) {
                doctor_process_mark_removing($this->instance);

                throw new DoctorInspectionException;
            }

            return new ProcessInspectionData(false, null);
        }
    };

    $report = new ProcessDoctorProbe($runtime)->inspect(doctor_process_context($node));

    expect($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->resourceId)
        ->toBe($nodeOwned->id)
        ->and($report->issues[0]->code)
        ->toBe('process.runtime_missing');
});

it('drops an Instance Process issue when its owner row is deleted during inspection', function (): void {
    $node = doctor_process_node();
    $process = doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Running);
    $instanceId = $process->owner_id;
    $runtime = new class($instanceId) implements ProcessStateInspector
    {
        public function __construct(private int $instanceId) {}

        public function inspect(Process $process): ProcessInspectionData
        {
            DB::table('instances')->where('id', $this->instanceId)->delete();

            return new ProcessInspectionData(false, null);
        }
    };

    $report = new ProcessDoctorProbe($runtime)->inspect(doctor_process_context($node));

    expect($report->issues)->toBeEmpty();
});

it('does not inspect runtimes when the node is unreachable', function (): void {
    $node = Node::query()->create([
        'name' => 'unreachable',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.21',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.4',
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

it('reports a crash-looping systemd process even when both samples read active', function (): void {
    $node = doctor_process_node();
    orbit_test_set_app_placement_role($node, false);
    $process = doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Running);
    $probe = doctor_systemd_probe([
        "ActiveState=active\nSubState=running\nNRestarts=3\n",
        "ActiveState=active\nSubState=running\nNRestarts=4\n",
    ]);

    $report = $probe->inspect(doctor_process_context($node));

    expect($report->checked)->toBe(1)
        ->and($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('process.crash_loop')
        ->and($report->issues[0]->kind->value)->toBe('drift')
        ->and($report->issues[0]->resourceId)->toBe($process->id)
        ->and($report->issues[0]->resourceName)->toBe($process->name)
        ->and($report->issues[0]->expected)->toBe('running')
        ->and($report->issues[0]->observed)->toBe('active/running; NRestarts=3 -> active/running; NRestarts=4');
});

it('keeps a systemd Process healthy after earlier restarts when the count is stable', function (): void {
    $node = doctor_process_node();
    orbit_test_set_app_placement_role($node, false);
    doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Running);
    $probe = doctor_systemd_probe([
        "ActiveState=active\nSubState=running\nNRestarts=26000\n",
        "ActiveState=active\nSubState=running\nNRestarts=26000\n",
    ]);

    $report = $probe->inspect(doctor_process_context($node));

    expect($report->checked)->toBe(1)->and($report->issues)->toBeEmpty();
});

it('reports auto-restart as one crash-loop issue instead of a state mismatch', function (array $samples, string $evidence): void {
    $node = doctor_process_node();
    orbit_test_set_app_placement_role($node, false);
    doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Running);
    $probe = doctor_systemd_probe($samples);

    $report = $probe->inspect(doctor_process_context($node));

    expect($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('process.crash_loop')
        ->and($report->issues[0]->observed)->toBe($evidence);
})->with([
    'restart wait with no counter available' => [
        ["ActiveState=activating\nSubState=auto-restart\n"],
        'activating/auto-restart; NRestarts=unavailable',
    ],
    'active before restart wait with stable count' => [
        ["ActiveState=active\nSubState=running\nNRestarts=4\n", "ActiveState=activating\nSubState=auto-restart\nNRestarts=4\n"],
        'active/running; NRestarts=4 -> activating/auto-restart; NRestarts=4',
    ],
]);

it('uses ordinary state comparison for activation without crash-loop evidence', function (): void {
    $node = doctor_process_node();
    orbit_test_set_app_placement_role($node, false);
    doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Running);
    $probe = doctor_systemd_probe([
        "ActiveState=activating\nSubState=start\nNRestarts=3\n",
        "ActiveState=activating\nSubState=start\nNRestarts=3\n",
    ]);

    $report = $probe->inspect(doctor_process_context($node));

    expect($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('process.state_mismatch')
        ->and($report->issues[0]->observed)->toBe('other');
});

it('does not apply crash-loop drift to a Process desired stopped', function (): void {
    $node = doctor_process_node();
    orbit_test_set_app_placement_role($node, false);
    doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Stopped);
    $probe = doctor_systemd_probe(["ActiveState=activating\nSubState=auto-restart\nNRestarts=3\n"]);

    $report = $probe->inspect(doctor_process_context($node));

    expect($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('process.state_mismatch')
        ->and($report->issues[0]->expected)->toBe('stopped');
    Sleep::assertNeverSlept();
});

it('does not apply systemd crash-loop evidence to a Docker Process', function (): void {
    $node = doctor_process_node();
    $process = doctor_process($node, ProcessRuntime::Docker, DesiredProcessState::Running);
    $runtime = Mockery::mock(ProcessStateInspector::class);
    $runtime->shouldReceive('inspect')->once()
        ->with(Mockery::on(fn (Process $value): bool => $value->is($process)))
        ->andReturn(new ProcessInspectionData(true, ProcessInspectionStatus::Running, [
            new SystemdProcessObservationData('activating', 'auto-restart', 3),
        ]));

    $report = new ProcessDoctorProbe($runtime)->inspect(doctor_process_context($node));

    expect($report->issues)->toBeEmpty();
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

it('selects only Instance processes on the exact target Node', function (): void {
    $node = doctor_process_node();
    $other = doctor_process_node();
    $selected = doctor_process($node, ProcessRuntime::Systemd, DesiredProcessState::Running, name: 'selected');
    doctor_process($other, ProcessRuntime::Systemd, DesiredProcessState::Running, name: 'excluded');
    $wrongMorph = $selected->replicate();
    $wrongMorph->owner_type = 'App\\Models\\Instance';
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

it('does not treat a sleeping non-keep-alive Process as drift', function (): void {
    [$node, $instance] = doctor_app_dev_instance();
    $vite = doctor_owned_process($instance, name: 'vite');
    $markers = new DoctorFakeHibernationMarkerStore;
    $runtime = Mockery::mock(ProcessStateInspector::class);
    $runtime
        ->shouldReceive('inspect')
        ->once()
        ->with(Mockery::on(fn (Process $process): bool => $process->is($vite)))
        ->andReturn(new ProcessInspectionData(true, ProcessInspectionStatus::Inactive));

    $report = new ProcessDoctorProbe($runtime, new DevelopmentHibernationPolicy, $markers)
        ->inspect(doctor_process_context($node));

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues)
        ->toBeEmpty();
});

it('still reports a keep-alive Process that is down while the group is asleep', function (): void {
    [$node, $instance] = doctor_app_dev_instance();
    $queue = doctor_owned_process($instance, name: 'queue', keepAlive: true);
    $markers = new DoctorFakeHibernationMarkerStore;
    $runtime = Mockery::mock(ProcessStateInspector::class);
    $runtime
        ->shouldReceive('inspect')
        ->once()
        ->with(Mockery::on(fn (Process $process): bool => $process->is($queue)))
        ->andReturn(new ProcessInspectionData(true, ProcessInspectionStatus::Inactive));

    $report = new ProcessDoctorProbe($runtime, new DevelopmentHibernationPolicy, $markers)
        ->inspect(doctor_process_context($node));

    expect($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('process.state_mismatch')
        ->and($report->issues[0]->resourceId)
        ->toBe($queue->id);
});

it('reports a non-keep-alive Process that is down while the Instance is awake', function (): void {
    [$node, $instance] = doctor_app_dev_instance();
    $vite = doctor_owned_process($instance, name: 'vite');
    $markers = new DoctorFakeHibernationMarkerStore;
    $markers->awake[RuntimeHibernation::key((int) $instance->id)] = true;
    $runtime = Mockery::mock(ProcessStateInspector::class);
    $runtime
        ->shouldReceive('inspect')
        ->once()
        ->andReturn(new ProcessInspectionData(true, ProcessInspectionStatus::Inactive));

    $report = new ProcessDoctorProbe($runtime, new DevelopmentHibernationPolicy, $markers)
        ->inspect(doctor_process_context($node));

    expect($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('process.state_mismatch');
});

/** @param list<string> $samples */
function doctor_systemd_probe(array $samples): ProcessDoctorProbe
{
    Sleep::fake();
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->andReturnUsing(
        static function (SshConnection $connection, RemoteCommand $command) use (&$samples): CommandResult {
            $output = match (array_slice($command->arguments, 0, 3)) {
                ['sudo', 'systemctl', 'is-active'] => "active\n",
                ['sudo', 'systemctl', 'show'] => array_shift($samples) ?? throw new RuntimeException('Unexpected sample.'),
                ['sudo', 'test', '-e'], ['sudo', 'grep', '-Fqx'] => '',
                default => throw new RuntimeException('Unexpected command.'),
            };

            return new CommandResult(0, $output, '', 1, false);
        },
    );
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/managed-key');
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/pinned-hosts');
    app()->instance(SshExecutor::class, $ssh);
    app()->instance(SshKeyProvider::class, $keys);
    app()->instance(KnownHostsStore::class, $hosts);

    return new ProcessDoctorProbe(app(NativeProcessStateInspector::class));
}

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
        'wireguard_ip' => "10.44.0.{$address}",
    ]);
}

function doctor_process_mark_removing(Instance $instance): void
{
    $trigger = DB::table('sqlite_master')
        ->where('type', 'trigger')
        ->where('name', 'instances_removal_status_update')
        ->value('sql');
    expect($trigger)->toBeString();
    DB::statement('DROP TRIGGER instances_removal_status_update');

    try {
        $instance->update(['status' => InstanceState::Removing]);
    } finally {
        DB::statement($trigger);
    }
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
    $slug = fake()->unique()->slug();
    $project = Project::query()->create([
        'name' => fake()->word(),
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => fake()->word(),
        'environment' => 'development',
        'checkout_path' => "/home/orbit/app/{$slug}",
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);

    return Process::query()->create([
        'owner_type' => Instance::MorphAlias,
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

/** @return array{0: Node, 1: Instance} */
function doctor_app_dev_instance(): array
{
    $node = doctor_process_node();
    $node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create([
        'name' => 'Docs',
        'slug' => fake()->unique()->slug(),
        'repository_url' => 'git@example.test:docs.git',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);

    return [$node, $instance];
}

function doctor_owned_process(Instance $instance, string $name, bool $keepAlive = false): Process
{
    return Process::query()->create([
        'owner_type' => Instance::MorphAlias,
        'owner_id' => $instance->id,
        'name' => $name,
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => $instance->checkout_path,
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => 'always',
        'keep_alive' => $keepAlive,
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
}

final class DoctorFakeHibernationMarkerStore implements HibernationMarkerStore
{
    /** @var array<string, bool> */
    public array $awake = [];

    public function markAwake(Node $node, string $key): void
    {
        $this->awake[$key] = true;
    }

    public function markAsleep(Node $node, string $key): void
    {
        $this->awake[$key] = false;
    }

    public function markCold(Node $node, string $key): void {}

    public function clearCold(Node $node, string $key): void {}

    public function lastActivityUnix(Node $node, string $key): ?int
    {
        return null;
    }

    public function isAwake(Node $node, string $key): bool
    {
        return $this->awake[$key] ?? false;
    }

    public function isCold(Node $node, string $key): bool
    {
        return false;
    }
}
