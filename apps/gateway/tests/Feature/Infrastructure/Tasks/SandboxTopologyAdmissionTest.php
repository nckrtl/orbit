<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskSandbox;

use function Pest\Laravel\mock;

/** @return array{Task, Task, object{fault: string|null, calls: list<string>, stale: bool}} */
function topology_admission_fixture(): array
{
    $host = Node::query()->create(['name' => 'compute', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'user' => 'orbit']);
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Ready topology', 'brief' => 'Work', 'status' => 'running', 'task_compute' => TaskCompute::Vm]);
    $task = Task::query()->create(['parent_id' => $group->id, 'title' => 'Turn', 'brief' => 'Work', 'position' => 1, 'status' => 'running']);
    $sandbox = TaskSandbox::query()->create(['id' => 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 'group_id' => $group->id, 'provider' => 'incus', 'name' => 'ot-0a68f778a3',
        'state' => 'running', 'desired_power' => 'running', 'spec' => ['host_id' => $host->id, 'project' => 'orbit-task-sandboxes',
            'pool' => 'proof', 'blocked_networks' => ['192.168.0.0/16'], 'subnet' => '10.233.7.0/24', 'source_template' => ['id' => '9862e1aa-605c-4b49-a65b-6cf0b3a96dfe', 'repository' => 'https://github.com/acme/orbit.git', 'base' => 'main', 'commit' => str_repeat('c', 40)],
            'images' => ['operator' => str_repeat('a', 64), 'gateway' => str_repeat('b', 64)]]]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $host->id, 'name' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/orbit', 'task_sandbox_id' => $sandbox->id]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);
    config(['compute.incus.hosts' => [['node_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'pool' => 'proof', 'max_vms' => 4,
        'orbit_images' => [], 'project_images' => [], 'blocked_networks' => ['192.168.0.0/16']]]]);
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    $state = (object) ['fault' => null, 'calls' => [], 'stale' => false];
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function ($connection, RemoteCommand $command) use ($sandbox, $state): CommandResult {
        $envelope = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        if (in_array($envelope['operation'], ['observe', 'provision'], true)) {
            $state->calls[] = $envelope['operation'];
            $roles = array_keys($sandbox->fresh()->spec['images']);
            if ($envelope['operation'] === 'provision') {
                expect($envelope['spec']['images'])->toBe($sandbox->fresh()->spec['images']);
                if ($state->fault === 'missing workload VM') {
                    $state->fault = null;
                }
            } elseif ($state->fault === 'missing workload VM') {
                $roles = ['operator', 'gateway'];
            }
            $instances = array_map(fn (string $role): array => ['name' => $sandbox->name.'-'.$role, 'state' => 'running'], $roles);

            return new CommandResult(0, json_encode(['name' => $sandbox->name, 'power' => 'running', 'instances' => $instances]), '', 1, false);
        }
        $request = json_decode(base64_decode($envelope['guest']['stdin']), true, flags: JSON_THROW_ON_ERROR);
        $state->calls[] = $request['phase'];
        $report = ['sandbox_id' => $sandbox->id, 'head' => str_repeat('a', 40), 'ready' => true];
        if ($request['phase'] === 'version') {
            $report += ['gateway_url' => 'https://10.44.0.1', 'gateway_head' => str_repeat($state->stale ? 'b' : 'a', 40)];
        }
        if ($request['phase'] === 'gateway') {
            expect($envelope['guest']['role'])->toBe('gateway');
            if ($state->fault === 'refresh failed') {
                $report['ready'] = false;
            }
        }
        if ($request['phase'] === 'prerequisites') {
            expect($envelope['guest']['role'])->toBe('gateway');
            if ($state->fault === 'prerequisites failed') {
                $report['ready'] = false;
            }
        }
        if ($request['phase'] === 'gateway-identity') {
            expect($envelope['guest']['role'])->toBe('gateway');
            $report['gateway_public_key'] = 'ssh-ed25519 '.base64_encode('public-fixture');
        }
        if ($request['phase'] === 'workload-identity') {
            expect($envelope['guest']['role'])->toBe($request['role']);
            $report += ['role' => $request['role'], 'architecture' => 'x86_64', 'fingerprint' => 'SHA256:'.str_repeat('a', 43)];
            if ($state->fault === 'foreign workload') {
                $report['sandbox_id'] = 'foreign';
            }
        }
        if ($request['phase'] === 'enroll') {
            expect($envelope['guest']['role'])->toBe('gateway');
            $report['enrolled_roles'] = [$request['role']];
            if ($state->fault === 'enrollment failed') {
                $report['ready'] = false;
            }
        }
        if ($request['phase'] === 'operator') {
            expect($request['doctor'])->toBeTrue();
            $report += ['gateway_url' => 'https://10.44.0.1', 'doctor_nodes' => $request['inventory']];
            match ($state->fault) {
                'foreign sandbox' => $report['sandbox_id'] = 'foreign',
                'old branch' => $report['head'] = str_repeat('b', 40),
                'live Gateway' => $report['gateway_url'] = 'https://10.44.0.2',
                'missing Node' => $report['doctor_nodes'] = ['gateway'],
                'unhealthy' => $report['ready'] = false,
                default => null,
            };
        }

        return new CommandResult(0, json_encode(['name' => $sandbox->name, 'role' => $envelope['guest']['role'], 'exit_code' => 0,
            'stdout' => base64_encode(json_encode($report)), 'stderr' => '', 'duration_ms' => 1, 'truncated' => false, 'timed_out' => false]), '', 1, false);
    });

    return [$group, $task, $state];
}

it('requires a fresh doctor receipt from the exact private inventory on each admission', function (): void {
    [$group, $task, $state] = topology_admission_fixture();

    app(TaskTopologyAdmission::class)->prepare($group, $task);
    app(TaskTopologyAdmission::class)->prepare($group, $task);

    expect($state->calls)->toBe(['inspect', 'version', 'operator', 'inspect', 'version', 'operator']);
});

it('keeps incomplete or foreign native readiness retryable', function (string $fault): void {
    [$group, $task, $state] = topology_admission_fixture();
    $state->fault = $fault;

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($group, $task))->toThrow(TaskCapacityException::class);
    expect($group->fresh()->task_compute)->toBe(TaskCompute::Vm);
})->with(['foreign sandbox', 'old branch', 'live Gateway', 'missing Node', 'unhealthy']);

it('refuses topology on the Project lane before contacting compute', function (): void {
    [$group, $task, $state] = topology_admission_fixture();
    $group->project->update(['slug' => 'dlf']);
    $task->update(['topology' => ['app-dev']]);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($group, $task))->toThrow(TaskCapacityException::class, 'Orbit VM group');
    expect($state->calls)->toBe([]);
});

it('refreshes a stale private Gateway before admitting a later turn', function (): void {
    [$group, $task, $state] = topology_admission_fixture();
    $state->stale = true;

    app(TaskTopologyAdmission::class)->prepare($group, $task);

    expect($state->calls)->toBe(['inspect', 'version', 'gateway', 'prerequisites', 'operator']);
});

it('keeps a failed runtime refresh retryable without confirming readiness', function (): void {
    [$group, $task, $state] = topology_admission_fixture();
    $state->stale = true;
    $state->fault = 'refresh failed';

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($group, $task))->toThrow(TaskCapacityException::class);
    expect($state->calls)->toBe(['inspect', 'version', 'gateway']);
});

it('does not expand or refresh a sandbox while its task check runs', function (): void {
    [$group, $task, $state] = topology_admission_fixture();
    $state->stale = true;
    TaskCheck::query()->create(['task_id' => $task->id, 'task_sandbox_id' => $group->taskable->task_sandbox_id,
        'kind' => 'baseline', 'status' => 'running', 'pid' => 4001, 'process_started' => 'Wed Sep 23 12:00:01 2026',
        'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now()]);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($group, $task))->toThrow(TaskCapacityException::class, 'check is still running');
    expect($state->calls)->toBe([]);
});

it('enrolls every reserved workload through the private Gateway before fresh doctor readiness', function (): void {
    [$group, $task, $state] = topology_admission_fixture();
    $sandbox = $group->taskable->taskSandbox;
    $sandbox->update(['spec' => [...$sandbox->spec, 'images' => [...$sandbox->spec['images'], 'app-dev' => str_repeat('c', 64), 'app-prod-2' => str_repeat('d', 64)]]]);
    $task->update(['topology' => ['app-dev']]);

    app(TaskTopologyAdmission::class)->prepare($group, $task);

    expect($state->calls)->toBe(['observe', 'inspect', 'version', 'gateway-identity', 'workload-identity', 'workload-identity', 'enroll', 'enroll', 'operator']);
});

it('keeps failed workload enrollment retryable without doctor admission', function (string $fault, array $calls): void {
    [$group, $task, $state] = topology_admission_fixture();
    $sandbox = $group->taskable->taskSandbox;
    $sandbox->update(['spec' => [...$sandbox->spec, 'images' => [...$sandbox->spec['images'], 'app-dev' => str_repeat('c', 64)]]]);
    $task->update(['topology' => ['app-dev']]);
    $state->fault = $fault;

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($group, $task))->toThrow(TaskCapacityException::class);
    expect($state->calls)->toBe($calls);
    expect($sandbox->fresh()->spec['images']['app-dev'])->toBe(str_repeat('c', 64));
})->with([
    'foreign workload' => ['foreign workload', ['observe', 'inspect', 'version', 'gateway-identity', 'workload-identity']],
    'failed native join' => ['enrollment failed', ['observe', 'inspect', 'version', 'gateway-identity', 'workload-identity', 'enroll']],
]);

it('retries a missing reserved workload before guest preparation without changing its images', function (): void {
    [$group, $task, $state] = topology_admission_fixture();
    $sandbox = $group->taskable->taskSandbox;
    $spec = [...$sandbox->spec, 'images' => [...$sandbox->spec['images'], 'app-dev' => str_repeat('c', 64)]];
    $sandbox->update(['spec' => $spec]);
    $task->update(['topology' => ['app-dev']]);
    $state->fault = 'missing workload VM';

    app(TaskTopologyAdmission::class)->prepare($group, $task);

    expect($state->calls)->toBe(['observe', 'provision', 'inspect', 'version', 'gateway-identity', 'workload-identity', 'enroll', 'operator']);
    expect($sandbox->fresh()->spec)->toBe($spec);
});

it('keeps failed native prerequisites retryable before doctor or dispatch', function (): void {
    [$group, $task, $state] = topology_admission_fixture();
    $state->stale = true;
    $state->fault = 'prerequisites failed';

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($group, $task))->toThrow(TaskCapacityException::class);
    expect($state->calls)->toBe(['inspect', 'version', 'gateway', 'prerequisites']);
    expect($group->fresh()->task_compute)->toBe(TaskCompute::Vm);
});
