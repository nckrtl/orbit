<?php

declare(strict_types=1);

use App\Actions\Compute\ReserveSandboxPiTokenAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\Compute\SandboxModelKeys;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Str;
use Tests\Support\FakeSandboxModelProxy;

use function Pest\Laravel\mock;

/** @return array{Task, TaskSandbox, object{calls: list<string>, fail: bool, power: string}, FakeSandboxModelProxy} */
function cleanup_sandbox_group(TaskCompute $mode = TaskCompute::Vm): array
{
    $proxy = new FakeSandboxModelProxy;
    $proxy->install();
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git', 'task_compute' => TaskCompute::Vm]);
    $host = Node::query()->create(['name' => 'compute', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'user' => 'orbit']);
    $settings = ['node_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'pool' => 'proof', 'max_vms' => 2,
        'orbit_images' => ['operator' => str_repeat('a', 64), 'gateway' => str_repeat('b', 64)],
        'project_images' => [], 'blocked_networks' => ['192.168.0.0/16']];
    config(['compute.incus.hosts' => [$settings]]);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Review work', 'brief' => 'Work', 'status' => TaskGroupStatus::Completed, 'task_compute' => $mode, 'pr_url' => 'https://github.com/acme/orbit/pull/42']);
    $id = (string) Str::uuid();
    $sandbox = TaskSandbox::query()->create(['id' => $id, 'group_id' => $group->id, 'provider' => 'incus', 'name' => 'ot-'.substr(hash('sha256', $id), 0, 10), 'state' => SandboxState::Running, 'desired_power' => 'running',
        'spec' => ['host_id' => $host->id, 'project' => $settings['project'], 'pool' => 'proof', 'images' => $settings['orbit_images'], 'subnet' => '10.233.201.0/24', 'blocked_networks' => $settings['blocked_networks']]]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $host->id, 'name' => 'task-'.$group->id, 'branch_override' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/orbit', 'status' => 'source_resolved', 'task_sandbox_id' => $id]);
    $group->taskable()->associate($workspace);
    $group->save();
    $state = (object) ['calls' => [], 'fail' => false, 'power' => 'destroyed'];
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function (SshConnection $connection, RemoteCommand $command) use ($state, $sandbox): CommandResult {
        $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        $state->calls[] = $request['operation'];
        if ($state->fail) {
            throw new RuntimeException('private-host-response');
        }
        $power = $state->power;

        return new CommandResult(0, json_encode(['name' => $sandbox->name, 'power' => $power, 'instances' => []], JSON_THROW_ON_ERROR), '', 1, false);
    });

    mock(InstanceRemover::class)->shouldNotReceive('execute');

    return [$group, $sandbox, $state, $proxy];
}

it('destroys compute before removing workspace references and keeps the reservation for audit', function (): void {
    [$group, $sandbox, $host] = cleanup_sandbox_group();
    $workspace = $group->taskable;
    app(SandboxModelKeys::class)->ensure($sandbox);
    app(ReserveSandboxPiTokenAction::class)->execute($sandbox);
    $child = Task::query()->create(['parent_id' => $group->id, 'title' => 'Done', 'brief' => 'Done', 'position' => 1, 'status' => TaskStatus::Completed]);

    app(RemoveTaskWorkspaceAction::class)->execute($group);
    app(RemoveTaskWorkspaceAction::class)->execute($group->fresh());

    expect($host->calls)->toBe(['destroy'])->and($sandbox->fresh()->state)->toBe(SandboxState::Destroyed)
        ->and($group->fresh()->taskable_id)->toBeNull()->and($child->fresh()->status)->toBe(TaskStatus::Completed)
        ->and($sandbox->fresh()->model_key)->toBeNull()->and($sandbox->fresh()->pi_token)->toBeNull();
    $this->assertModelMissing($workspace);
});

it('retains the workspace and retries after compute destruction fails', function (): void {
    [$group, $sandbox, $host] = cleanup_sandbox_group();
    $workspace = $group->taskable;
    $host->fail = true;
    expect(fn () => app(RemoveTaskWorkspaceAction::class)->execute($group))->toThrow(ComputeException::class);
    $this->assertModelExists($workspace);
    expect($group->fresh()->taskable_id)->toBe($workspace->id);
    $host->fail = false;

    app(RemoveTaskWorkspaceAction::class)->execute($group->fresh());

    expect($host->calls)->toBe(['destroy', 'destroy'])->and($sandbox->fresh()->state)->toBe(SandboxState::Destroyed);
    $this->assertModelMissing($workspace);
});

it('retains the workspace when destruction is not confirmed', function (): void {
    [$group, $sandbox, $host] = cleanup_sandbox_group();
    $host->power = 'stopped';
    expect(fn () => app(RemoveTaskWorkspaceAction::class)->execute($group))->toThrow(ComputeException::class);
    $this->assertModelExists($group->taskable);
});

it('refuses mismatched ownership without touching compute', function (string $mismatch): void {
    [$group, $sandbox, $host] = cleanup_sandbox_group($mismatch === 'shared' ? TaskCompute::Shared : TaskCompute::Vm);
    $workspace = $group->taskable;
    match ($mismatch) {
        'shared' => null,
        'external' => $group->update(['execution_mode' => TaskExecutionMode::ExistingThread]),
        'host' => $sandbox->update(['spec' => [...$sandbox->spec, 'host_id' => 999]]),
        'node' => $sandbox->update(['node_id' => $workspace->node_id]),
        'missing' => $workspace->update(['task_sandbox_id' => null]),
        'foreign' => Task::topLevel()->create(['project_id' => $group->project_id, 'title' => 'Foreign', 'brief' => 'Keep', 'status' => TaskGroupStatus::Todo,
            'taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]),
    };

    expect(fn () => app(RemoveTaskWorkspaceAction::class)->execute($group->fresh()))->toThrow(ComputeException::class);

    expect($host->calls)->toBe([])->and($sandbox->fresh()->state)->toBe(SandboxState::Running);
    $this->assertModelExists($workspace);
})->with(['shared', 'external', 'host', 'node', 'missing', 'foreign']);

it('cleans an unattached named sandbox without using the host checkout remover', function (): void {
    [$group, $sandbox, $host] = cleanup_sandbox_group();
    $workspace = $group->taskable;
    $group->taskable()->dissociate();
    $group->save();

    app(RemoveTaskWorkspaceAction::class)->execute($group);

    expect($host->calls)->toBe(['destroy']);
    $this->assertModelMissing($workspace);
});

it('sweeps a reservation left before workspace attachment with retry backoff', function (): void {
    [$group, $sandbox, $host] = cleanup_sandbox_group();
    $group->taskable->delete();
    $group->taskable()->dissociate();
    $group->save();
    $host->fail = true;
    $scheduler = app(TaskScheduler::class);
    expect($scheduler->removeAbandonedWorkspaces())->toBe(0);
    expect($scheduler->removeAbandonedWorkspaces())->toBe(0);
    expect($host->calls)->toBe(['destroy'])->and($group->fresh()->assistance_reason)->toStartWith(RemoveTaskWorkspaceAction::RemovalFailedPrefix);
    $host->fail = false;
    $this->travel(61)->seconds();

    expect($scheduler->removeAbandonedWorkspaces())->toBe(1);

    expect($host->calls)->toBe(['destroy', 'destroy'])->and($sandbox->fresh()->state)->toBe(SandboxState::Destroyed)
        ->and($group->fresh()->assistance_requested)->toBeFalse();
});

it('leaves active and recently reserved groups out of orphan cleanup', function (TaskGroupStatus $status, bool $recent): void {
    [$group, $sandbox, $host] = cleanup_sandbox_group();
    $group->taskable->delete();
    $group->taskable()->dissociate();
    $group->fill(['status' => $status, 'reserved_at' => $recent ? now() : null])->save();

    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0);

    expect($host->calls)->toBe([])->and($sandbox->fresh()->state)->toBe(SandboxState::Running);
})->with([[TaskGroupStatus::Running, false], [TaskGroupStatus::Cancelled, true]]);

it('recovers a recorded sandbox after its group was deleted', function (): void {
    [$group, $sandbox, $host] = cleanup_sandbox_group();
    $group->taskable->delete();
    $group->delete();

    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1);

    expect($host->calls)->toBe(['destroy'])->and($sandbox->fresh()->group_id)->toBeNull()->and($sandbox->fresh()->state)->toBe(SandboxState::Destroyed);
});

it('refuses a workspace with a live process before revoking or destroying compute', function (): void {
    [$group, $sandbox, $host] = cleanup_sandbox_group();
    $workspace = $group->taskable;
    $process = $workspace->processes()->create(['name' => 'queue', 'runtime' => 'systemd', 'working_directory' => $workspace->checkout_path,
        'runtime_config' => ['command' => ['/bin/true']], 'restart_policy' => 'always', 'desired_state' => 'stopped', 'status' => 'active']);

    expect(fn () => app(RemoveTaskWorkspaceAction::class)->execute($group))->toThrow(ComputeException::class, 'live resource references');

    expect($host->calls)->toBe([])->and($sandbox->fresh()->desired_power)->toBe('running');
    $this->assertModelExists($workspace);
    $this->assertModelExists($process);
});

it('keeps compute and workspace intact when model key revocation fails', function (): void {
    [$group, $sandbox, $host, $proxy] = cleanup_sandbox_group();
    app(SandboxModelKeys::class)->ensure($sandbox);
    $proxy->available = false;

    expect(fn () => app(RemoveTaskWorkspaceAction::class)->execute($group))->toThrow(ComputeException::class);

    expect($host->calls)->toBe([])->and($sandbox->fresh()->model_key)->not->toBeNull();
    $this->assertModelExists($group->taskable);
});

it('refuses a foreign reservation found through a leftover workspace name', function (): void {
    [$owner, $sandbox, $host] = cleanup_sandbox_group();
    $other = Task::topLevel()->create(['project_id' => $owner->project_id, 'title' => 'Other', 'brief' => 'Ended', 'status' => TaskGroupStatus::Completed, 'task_compute' => TaskCompute::Vm]);
    $owner->taskable->update(['name' => 'task-'.$other->id, 'branch_override' => 'task-'.$other->id]);

    expect(fn () => app(RemoveTaskWorkspaceAction::class)->execute($other))->toThrow(ComputeException::class);

    expect($host->calls)->toBe([])->and($sandbox->fresh()->state)->toBe(SandboxState::Running);
    $this->assertModelExists($owner->taskable);
});
