<?php

declare(strict_types=1);

use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\Compute\TaskSandboxGroupLifecycle;
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

/** @return array{Task, TaskSandbox, object{calls: list<string>, fail: bool}} */
function review_sandbox_group(): array
{
    (new FakeSandboxModelProxy)->install();
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git', 'task_compute' => TaskCompute::Vm]);
    $host = Node::query()->create(['name' => 'compute', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'user' => 'orbit']);
    $settings = ['node_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'pool' => 'proof', 'max_vms' => 2,
        'orbit_images' => ['operator' => str_repeat('a', 64), 'gateway' => str_repeat('b', 64)],
        'project_images' => [], 'blocked_networks' => ['192.168.0.0/16']];
    config(['compute.incus.hosts' => [$settings]]);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Review work', 'brief' => 'Work', 'status' => TaskGroupStatus::WaitingForReview, 'task_compute' => TaskCompute::Vm, 'pr_url' => 'https://github.com/acme/orbit/pull/42']);
    $id = (string) Str::uuid();
    $sandbox = TaskSandbox::query()->create(['id' => $id, 'group_id' => $group->id, 'provider' => 'incus', 'name' => 'ot-'.substr(hash('sha256', $id), 0, 10), 'state' => SandboxState::Running, 'desired_power' => 'running',
        'spec' => ['host_id' => $host->id, 'project' => $settings['project'], 'pool' => 'proof', 'images' => $settings['orbit_images'], 'subnet' => '10.233.201.0/24', 'blocked_networks' => $settings['blocked_networks']]]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $host->id, 'name' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/orbit', 'status' => 'source_resolved', 'task_sandbox_id' => $id]);
    $group->taskable()->associate($workspace);
    $group->save();
    $state = (object) ['calls' => [], 'fail' => false];
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function (SshConnection $connection, RemoteCommand $command) use ($state, $sandbox): CommandResult {
        $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        $state->calls[] = $request['operation'];
        if ($state->fail) {
            throw new RuntimeException('private-host-response');
        }
        $power = $request['operation'] === 'park' ? 'stopped' : 'running';

        return new CommandResult(0, json_encode(['name' => $sandbox->name, 'power' => $power, 'instances' => array_map(
            fn (string $role): array => ['name' => $sandbox->name.'-'.$role, 'state' => $power], ['operator', 'gateway'],
        )], JSON_THROW_ON_ERROR), '', 1, false);
    });

    return [$group, $sandbox, $state];
}

it('reconciles an owned review wait through grace and a confirmed park', function (): void {
    [$group, $sandbox, $host] = review_sandbox_group();
    $lifecycle = app(TaskSandboxGroupLifecycle::class);
    $lifecycle->review($group);
    expect($host->calls)->toBe([])->and($sandbox->fresh()->review_started_at)->not->toBeNull();
    $this->travel(5)->minutes();
    $lifecycle->review($group);
    $lifecycle->review($group);

    expect($host->calls)->toBe(['park'])->and($sandbox->fresh()->state)->toBe(SandboxState::Stopped)
        ->and($group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview);
});

it('ends grace only for an eligible VM capacity waiter', function (TaskCompute $mode, bool $assistance, bool $shouldPark, ?string $reason = null): void {
    [$group, $sandbox, $host] = review_sandbox_group();
    $waiter = Task::topLevel()->create(['project_id' => $group->project_id, 'title' => 'Waiter', 'brief' => 'Work', 'status' => TaskGroupStatus::Todo, 'task_compute' => $mode, 'assistance_requested' => $assistance, 'assistance_reason' => $reason]);
    Task::query()->create(['parent_id' => $waiter->id, 'title' => 'Work', 'brief' => 'Work', 'position' => 1, 'status' => TaskStatus::Todo]);
    app(TaskSandboxGroupLifecycle::class)->review($group);

    expect($host->calls)->toBe($shouldPark ? ['park'] : [])
        ->and($sandbox->fresh()->state)->toBe($shouldPark ? SandboxState::Stopped : SandboxState::Running);
})->with([
    'VM ready' => [TaskCompute::Vm, false, true],
    'shared' => [TaskCompute::Shared, false, false],
    'VM assistance' => [TaskCompute::Vm, true, false],
    'VM pull request fixup' => [TaskCompute::Vm, true, true, 'The pull request needs attention: CI failed.'],
]);

it('does not park while a subtask can still work', function (TaskStatus $status): void {
    [$group, $sandbox, $host] = review_sandbox_group();
    Task::query()->create(['parent_id' => $group->id, 'title' => 'Work', 'brief' => 'Work', 'position' => 1, 'status' => $status]);
    app(TaskSandboxGroupLifecycle::class)->review($group);

    expect($host->calls)->toBe([])->and($sandbox->fresh()->review_started_at)->toBeNull();
})->with([TaskStatus::Todo, TaskStatus::Reserved, TaskStatus::Running, TaskStatus::Reviewing]);

it('keeps a failed park visible and retries without exposing host output', function (): void {
    [$group, $sandbox, $host] = review_sandbox_group();
    $sandbox->update(['review_started_at' => now()->subMinutes(6)]);
    $host->fail = true;
    $lifecycle = app(TaskSandboxGroupLifecycle::class);
    $lifecycle->review($group);
    expect($group->fresh()->capacity_wait_reason)->toStartWith('Sandbox compute: ')->not->toContain('private-host-response');
    $host->fail = false;
    $lifecycle->review($group);

    expect($sandbox->fresh()->state)->toBe(SandboxState::Stopped)->and($group->fresh()->capacity_wait_reason)->toBeNull();
});

it('restores owned compute before admitting resumed work', function (): void {
    [$group, $sandbox, $host] = review_sandbox_group();
    $sandbox->update(['state' => SandboxState::Stopped, 'desired_power' => 'stopped', 'review_started_at' => now()->subMinutes(6)]);
    expect(app(TaskSandboxGroupLifecycle::class)->resume($group))->toBeTrue();

    expect($host->calls)->toBe(['resume'])->and($sandbox->fresh()->state)->toBe(SandboxState::Running)
        ->and($sandbox->fresh()->review_started_at)->toBeNull();
});

it('refuses a foreign sandbox workspace and never falls back to the shared host', function (): void {
    [$group, $sandbox, $host] = review_sandbox_group();
    $other = Task::topLevel()->create(['project_id' => $group->project_id, 'title' => 'Other', 'brief' => 'Other', 'status' => TaskGroupStatus::Todo]);
    $sandbox->update(['group_id' => $other->id]);
    expect(app(TaskSandboxGroupLifecycle::class)->resume($group))->toBeFalse();

    expect($host->calls)->toBe([])->and($group->fresh()->capacity_wait_reason)->toContain('matching sandbox workspace');
});

it('does not resume a group after completion has been authorized', function (): void {
    [$group, $sandbox, $host] = review_sandbox_group();
    $group->update(['watched_pr_completion' => 'merged']);
    $lifecycle = app(TaskSandboxGroupLifecycle::class);
    $lifecycle->review($group);
    expect($lifecycle->resume($group))->toBeFalse();

    expect($host->calls)->toBe([])->and($sandbox->fresh()->review_started_at)->toBeNull();
});

it('requires a cloud rebuild instead of starting the old stopped server', function (): void {
    [$group, $sandbox, $host] = review_sandbox_group();
    $group->project->update(['slug' => 'dlf']);
    $sandbox->update(['provider' => 'upcloud', 'node_id' => $group->taskable->node_id, 'state' => SandboxState::Stopped, 'desired_power' => 'stopped']);
    expect(app(TaskSandboxGroupLifecycle::class)->resume($group))->toBeFalse();

    expect($host->calls)->toBe([])->and($group->fresh()->capacity_wait_reason)->toContain('rebuilt from its published branch');
});

it('attempts a waiting VM resume before provisioning a new todo group', function (): void {
    [$waiting, $sandbox, $host] = review_sandbox_group();
    Task::query()->create(['parent_id' => $waiting->id, 'title' => 'Fixup', 'brief' => 'Work', 'position' => 1, 'status' => TaskStatus::Todo]);
    $waiting->taskable()->dissociate();
    $waiting->save();
    $project = Project::query()->create(['name' => 'Shared', 'slug' => 'shared', 'repository_url' => 'https://github.com/acme/shared.git']);
    $todo = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'New work', 'brief' => 'Work', 'status' => TaskGroupStatus::Todo]);
    Task::query()->create(['parent_id' => $todo->id, 'title' => 'First', 'brief' => 'Work', 'position' => 1, 'status' => TaskStatus::Todo]);
    mock(InstanceProvisioning::class)->shouldReceive('provision')->once()->andReturnUsing(function () use ($waiting): null {
        expect($waiting->fresh()->capacity_wait_reason)->toContain('matching sandbox workspace');

        return null;
    });
    expect(app(TaskScheduler::class)->claimNext())->toBeNull();

    expect($host->calls)->toBe([])->and($waiting->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview);
});

it('reconciles requester preview intent through park and resume without resetting the review deadline', function (): void {
    [$group, $sandbox, $host] = review_sandbox_group();
    $started = now()->startOfSecond()->subMinutes(6);
    $sandbox->update(['review_started_at' => $started]);
    $group->update(['preview' => true]);
    $lifecycle = app(TaskSandboxGroupLifecycle::class);
    $lifecycle->review($group);
    expect($host->calls)->toBe([])->and($sandbox->fresh()->preview)->toBeTrue();

    $group->update(['preview' => false]);
    $lifecycle->review($group);
    expect($host->calls)->toBe(['park'])->and($sandbox->fresh()->preview)->toBeFalse();

    $group->update(['preview' => true]);
    $lifecycle->review($group);
    expect($host->calls)->toBe(['park', 'resume'])->and($sandbox->fresh()->state)->toBe(SandboxState::Running)
        ->and($sandbox->fresh()->review_started_at->equalTo($started))->toBeTrue();
});

it('keeps cloud preview waiting for a branch rebuild without starting the old server', function (SandboxState $state, string $power): void {
    [$group, $sandbox, $host] = review_sandbox_group();
    $group->project->update(['slug' => 'dlf']);
    $group->update(['preview' => true]);
    $sandbox->update(['provider' => 'upcloud', 'node_id' => $group->taskable->node_id, 'state' => $state, 'desired_power' => $power]);

    app(TaskSandboxGroupLifecycle::class)->review($group);

    expect($host->calls)->toBe([])
        ->and($group->fresh()->preview)->toBeTrue()
        ->and($group->fresh()->capacity_wait_reason)->toContain('rebuilt from its published branch')
        ->and($sandbox->fresh()->state)->toBe($state)
        ->and($sandbox->fresh()->desired_power)->toBe($power);
})->with([
    'parked' => [SandboxState::Stopped, 'stopped'],
    'park pending' => [SandboxState::Running, 'stopped'],
    'restore pending' => [SandboxState::Stopped, 'running'],
    'expired' => [SandboxState::Destroyed, 'destroyed'],
]);
