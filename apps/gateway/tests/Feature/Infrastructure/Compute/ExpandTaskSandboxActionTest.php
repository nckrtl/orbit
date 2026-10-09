<?php

declare(strict_types=1);

use App\Actions\Compute\ExpandTaskSandboxAction;
use App\Domain\Compute\ComputeException;
use App\Domain\Tasks\TaskCompute;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;

use function Pest\Laravel\mock;

/** @return array{Instance, TaskSandbox, object{available: int, fail: bool, calls: list<string>}} */
function expandable_sandbox(): array
{
    $host = Node::query()->create(['name' => 'host', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'user' => 'orbit']);
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git', 'apps' => fixture_apps(null)]);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Expansion', 'brief' => 'Work', 'status' => 'running', 'task_compute' => TaskCompute::Vm]);
    $images = ['operator' => str_repeat('a', 64), 'gateway' => str_repeat('b', 64)];
    $sandbox = TaskSandbox::query()->create(['id' => 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 'group_id' => $group->id, 'provider' => 'incus',
        'name' => 'ot-0a68f778a3', 'state' => 'running', 'desired_power' => 'running',
        'spec' => ['host_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'pool' => 'proof', 'images' => $images,
            'subnet' => '10.233.7.0/24', 'blocked_networks' => ['192.168.0.0/16'],
            'source_template' => ['id' => '9862e1aa-605c-4b49-a65b-6cf0b3a96dfe', 'repository' => 'https://github.com/acme/orbit.git', 'base' => 'main', 'commit' => str_repeat('c', 40)]]]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $host->id, 'name' => 'task-'.$group->id,
        'checkout_path' => '/home/orbit/orbit', 'status' => 'source_resolved', 'task_sandbox_id' => $sandbox->id]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);
    config(['compute.incus.hosts' => [['node_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'pool' => 'proof', 'max_vms' => 4,
        'orbit_images' => [...$images, 'app-dev' => str_repeat('c', 64)], 'orbit_source_template' => $sandbox->spec['source_template'], 'project_images' => [], 'blocked_networks' => ['192.168.0.0/16']]]]);
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    $state = (object) ['available' => 2, 'fail' => false, 'calls' => []];
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function ($connection, RemoteCommand $command) use ($state, $sandbox): CommandResult {
        $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        $state->calls[] = $request['operation'];
        if ($request['operation'] === 'capacity') {
            return new CommandResult(0, json_encode(['available' => $state->available, 'used' => 4 - $state->available, 'budget' => 4]), '', 1, false);
        }
        expect($request['operation'])->toBe('provision');
        expect($request['spec']['images'])->toBe($sandbox->fresh()->spec['images']);
        if ($state->fail) {
            return new CommandResult(1, '', 'failed', 1, false);
        }
        $instances = array_map(fn (string $role): array => ['name' => $sandbox->name.'-'.$role, 'state' => 'running'], array_keys($request['spec']['images']));

        return new CommandResult(0, json_encode(['name' => $sandbox->name, 'power' => 'running', 'instances' => $instances]), '', 1, false);
    });

    return [$workspace, $sandbox, $state];
}

it('adds declared VM reservations without replacing pair images or placement', function (): void {
    [$workspace, $sandbox, $state] = expandable_sandbox();
    $before = $sandbox->spec;
    $result = app(ExpandTaskSandboxAction::class)->execute($workspace, ['app-dev']);
    expect($result->id)->toBe($sandbox->id);
    expect($result->spec)->toBe([...$before, 'images' => [...$before['images'], 'app-dev' => str_repeat('c', 64)]]);
    expect($state->calls)->toBe(['capacity', 'provision']);
});

it('waits without reserving when the observed VM budget is full', function (): void {
    [$workspace, $sandbox, $state] = expandable_sandbox();
    $state->available = 0;
    $before = $sandbox->spec;
    expect(fn () => app(ExpandTaskSandboxAction::class)->execute($workspace, ['app-dev']))->toThrow(ComputeException::class, 'no capacity');
    expect($sandbox->fresh()->spec)->toBe($before);
    expect($state->calls)->toBe(['capacity']);
});

it('counts other pending reservations even when the host still reports capacity', function (): void {
    [$workspace, $sandbox, $state] = expandable_sandbox();
    TaskSandbox::query()->create(['id' => '7789c929-5bf9-4a95-a4de-aa251bf5e9dd', 'provider' => 'incus', 'name' => 'reserved',
        'state' => 'reserved', 'desired_power' => 'running', 'spec' => ['host_id' => $sandbox->spec['host_id'], 'images' => ['operator' => 'a', 'gateway' => 'b']]]);
    expect(fn () => app(ExpandTaskSandboxAction::class)->execute($workspace, ['app-dev']))->toThrow(ComputeException::class, 'reserved by other');
    expect(array_keys($sandbox->fresh()->spec['images']))->toBe(['operator', 'gateway']);
    expect($state->calls)->toBe(['capacity']);
});

it('retains an incomplete expansion and retries the same reservation', function (): void {
    [$workspace, $sandbox, $state] = expandable_sandbox();
    $state->fail = true;
    expect(fn () => app(ExpandTaskSandboxAction::class)->execute($workspace, ['app-dev']))->toThrow(ComputeException::class);
    expect(array_keys($sandbox->fresh()->spec['images']))->toBe(['operator', 'gateway', 'app-dev']);
    $state->fail = false;
    $state->available = 0;
    expect(app(ExpandTaskSandboxAction::class)->execute($workspace, ['app-dev'])->id)->toBe($sandbox->id);
    expect($state->calls)->toBe(['capacity', 'provision', 'provision']);
    expect(TaskSandbox::query()->count())->toBe(1);
});

it('refuses missing images and parked sandboxes before contacting the host', function (): void {
    [$workspace, $sandbox, $state] = expandable_sandbox();
    expect(fn () => app(ExpandTaskSandboxAction::class)->execute($workspace, ['app-prod']))->toThrow(ComputeException::class, 'No pinned image');
    $sandbox->update(['state' => 'stopped', 'desired_power' => 'stopped']);
    expect(fn () => app(ExpandTaskSandboxAction::class)->execute($workspace, ['app-dev']))->toThrow(ComputeException::class, 'Resume');
    expect($state->calls)->toBe([]);
});

it('refuses changed template configuration before reserving workload roles', function (string $fault): void {
    [$workspace, $sandbox, $state] = expandable_sandbox();
    $host = config('compute.incus.hosts')[0];
    match ($fault) {
        'missing template' => $host['orbit_source_template'] = null,
        'changed template' => $host['orbit_source_template']['id'] = '7789c929-5bf9-4a95-a4de-aa251bf5e9dd',
        'changed commit' => $host['orbit_source_template']['commit'] = str_repeat('d', 40),
        'changed pair image' => $host['orbit_images']['gateway'] = str_repeat('d', 64),
    };
    config(['compute.incus.hosts' => [$host]]);
    $before = $sandbox->spec;

    expect(fn () => app(ExpandTaskSandboxAction::class)->execute($workspace, ['app-dev']))
        ->toThrow(ComputeException::class, 'recorded template');
    expect($sandbox->fresh()->spec)->toBe($before);
    expect($state->calls)->toBe([]);
})->with(['missing template', 'changed template', 'changed commit', 'changed pair image']);

it('retries already reserved roles from their recorded images after host configuration changes', function (): void {
    [$workspace, $sandbox, $state] = expandable_sandbox();
    $state->fail = true;
    expect(fn () => app(ExpandTaskSandboxAction::class)->execute($workspace, ['app-dev']))->toThrow(ComputeException::class);
    $before = $sandbox->fresh()->spec;
    $host = config('compute.incus.hosts')[0];
    $host['orbit_source_template']['id'] = '7789c929-5bf9-4a95-a4de-aa251bf5e9dd';
    $host['orbit_images']['app-dev'] = str_repeat('d', 64);
    config(['compute.incus.hosts' => [$host]]);
    $state->fail = false;

    expect(app(ExpandTaskSandboxAction::class)->execute($workspace, ['app-dev'])->spec)->toBe($before);
    expect($state->calls)->toBe(['capacity', 'provision', 'provision']);
});
