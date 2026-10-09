<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\TaskCompute;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceMcp;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceTopology;
use App\Infrastructure\Tasks\TaskWorkerUser;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

function sandbox_workspace(): Instance
{
    $host = Node::query()->create(['name' => 'compute', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'user' => 'managed-host']);
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git', 'apps' => fixture_apps(null)]);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Sandbox', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
    $sandbox = TaskSandbox::query()->create([
        'id' => 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 'group_id' => $group->id, 'provider' => 'incus',
        'name' => 'ot-0a68f778a3', 'state' => SandboxState::Running, 'desired_power' => 'running',
        'spec' => ['host_id' => $host->id, 'project' => 'orbit-task-sandboxes'],
    ]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $host->id, 'name' => 'task-'.$group->id,
        'checkout_path' => '/home/orbit/orbit', 'task_sandbox_id' => $sandbox->id]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);
    config(['compute.incus.hosts' => [['node_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'pool' => 'proof', 'max_vms' => 4,
        'orbit_images' => [], 'project_images' => [], 'blocked_networks' => ['192.168.0.0/16']]], 'orbit.tasks.worker_user' => 'orbit-worker']);

    return $workspace;
}

beforeEach(function (): void {
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
});

describe('sandbox workspace commands', function (): void {
    it('installs MCP metadata inside the guest without using the shared worker', function (): void {
        $workspace = sandbox_workspace();
        config(['app.url' => 'https://live-gateway.example']);
        mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(function (SshConnection $connection, RemoteCommand $command): CommandResult {
            expect($command->arguments)->toBe(['/usr/local/bin/orbit-agent', 'sandbox']);
            $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            expect($request['guest']['argv'])->toBe(['bash', '-seu', '--', '/home/orbit/orbit']);
            expect(base64_decode($request['guest']['stdin']))->toContain('command git -c core.hooksPath=/dev/null')->not->toContain('orbit-worker');

            return new CommandResult(0, json_encode(['name' => 'ot-0a68f778a3', 'role' => 'operator', 'exit_code' => 0,
                'stdout' => base64_encode('installed'), 'stderr' => '', 'duration_ms' => 1, 'truncated' => false, 'timed_out' => false], JSON_THROW_ON_ERROR), '', 1, false);
        });

        expect(app(RemoteTaskWorkspaceMcp::class)->installWhenMissing($workspace))->toBeTrue();
    });

    it('never invokes the host topology harness for a VM group', function (string $operation, bool $missingOwnership): void {
        $workspace = sandbox_workspace();
        $groupId = $workspace->taskSandbox->group_id;
        if ($missingOwnership) {
            $workspace->update(['task_sandbox_id' => null]);
        }
        mock(SshExecutor::class)->shouldReceive('execute')->never();

        expect(fn () => app(RemoteTaskWorkspaceTopology::class)->{$operation}($workspace, $groupId))
            ->toThrow(RuntimeConvergenceException::class, 'Sandbox workload nodes must be managed by the compute driver.');
    })->with(['acquire', 'release'])->with([false, true]);

    it('executes only in the recorded guest and bypasses the shared worker account', function (): void {
        $workspace = sandbox_workspace();
        mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(function (SshConnection $connection, RemoteCommand $command): CommandResult {
            expect($connection->user)->toBe('managed-host')->and($command->arguments)->toBe(['/usr/local/bin/orbit-agent', 'sandbox']);
            $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            expect($request['guest']['argv'])->toBe(['id'])->and($request['guest']['timeout'])->toBe(15);

            return new CommandResult(0, json_encode(['name' => 'ot-0a68f778a3', 'role' => 'operator', 'exit_code' => 0,
                'stdout' => base64_encode('orbit'), 'stderr' => '', 'duration_ms' => 1, 'truncated' => false, 'timed_out' => false], JSON_THROW_ON_ERROR), '', 1, false);
        });
        expect(TaskWorkerUser::name($workspace))->toBeNull()->and(TaskWorkerUser::arguments(['id'], $workspace))->toBe(['id']);
        $result = app(TaskWorkspaceExecutor::class)->execute($workspace, new RemoteCommand(['id']), 'proof', 'tasks.proof', 15);
        expect($result->stdout)->toBe('orbit');
    });

    it('never falls back to the shared host after ownership state or placement changes', function (string $fault): void {
        $workspace = sandbox_workspace();
        $sandbox = $workspace->taskSandbox;
        match ($fault) {
            'parked' => $sandbox->update(['state' => SandboxState::Stopped]),
            'missing reservation' => $workspace->update(['task_sandbox_id' => null]),
            'foreign host' => $sandbox->update(['spec' => ['host_id' => 999, 'project' => 'orbit-task-sandboxes']]),
            'wrong provider' => $sandbox->update(['provider' => 'foreign']),
            'foreign workspace' => $sandbox->group->update(['taskable_id' => null]),
            'unknown cloud node' => $sandbox->update(['provider' => 'upcloud']),
        };
        mock(SshExecutor::class)->shouldReceive('execute')->never();
        expect(fn () => app(TaskWorkspaceExecutor::class)->execute($workspace->fresh(), new RemoteCommand(['id']), 'proof', 'tasks.proof'))
            ->toThrow(RuntimeConvergenceException::class);
    })->with(['parked', 'missing reservation', 'foreign host', 'wrong provider', 'foreign workspace', 'unknown cloud node']);

    it('keeps shared workspace commands on the existing transport', function (): void {
        $workspace = sandbox_workspace();
        $workspace->taskSandbox->group->delete();
        $workspace->update(['task_sandbox_id' => null]);
        mock(SshExecutor::class)->shouldReceive('execute')->once()->withArgs(fn (SshConnection $connection, RemoteCommand $command): bool => $command->arguments === ['id'])
            ->andReturn(new CommandResult(0, 'managed-host', '', 1, false));
        expect(app(TaskWorkspaceExecutor::class)->execute($workspace->fresh(), new RemoteCommand(['id']), 'proof', 'tasks.proof')->stdout)->toBe('managed-host')
            ->and(TaskWorkerUser::name($workspace))->toBe('orbit-worker');
    });

    it('starts sandbox checks without host runtime probes or host seed paths', function (): void {
        $workspace = sandbox_workspace();
        $workspace->update(['seed_path' => '/host/secret-seed', 'seed_commit' => str_repeat('b', 40)]);
        mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(function (SshConnection $connection, RemoteCommand $command): CommandResult {
            expect($command->arguments)->toBe(['/usr/local/bin/orbit-agent', 'sandbox']);
            $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            $script = base64_decode($request['guest']['stdin']);
            expect($script)->toContain("worker=''", "seed_path=''", "seed_commit=''")->not->toContain('/host/secret-seed', 'export VP_HOME=');
            $started = json_encode(['pid' => 123, 'started' => '2026-10-07', 'head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40)], JSON_THROW_ON_ERROR);

            return new CommandResult(0, json_encode(['name' => 'ot-0a68f778a3', 'role' => 'operator', 'exit_code' => 0,
                'stdout' => base64_encode($started), 'stderr' => '', 'duration_ms' => 1, 'truncated' => false, 'timed_out' => false], JSON_THROW_ON_ERROR), '', 1, false);
        });
        expect(app(RemoteTaskCheckRunner::class)->start($workspace, 'composer check')->pid)->toBe(123);
    });
});

it('allocates a private sandbox check directory without invoking host ACL tools', function (): void {
    $process = new Process(['python3', '-c', <<<'PYTHON'
        import os, pathlib, runpy, stat, sys, tempfile
        from unittest.mock import patch
        metadata = runpy.run_path(sys.argv[1])
        with tempfile.TemporaryDirectory() as checkout:
            pathlib.Path(checkout, '.git').mkdir()
            with patch('subprocess.run', side_effect=AssertionError('Unexpected ACL command')):
                directory = metadata['perform'](checkout, 'tmpdir', {'sandbox': True})
            try:
                assert stat.S_IMODE(os.stat(directory).st_mode) == 0o700
                assert os.stat(directory).st_uid == os.geteuid()
            finally:
                os.rmdir(directory)
        PYTHON, resource_path('tasks/metadata')]);
    $process->mustRun();
    expect($process->getExitCode())->toBe(0);
});

it('never routes a test Gateway role through shared or project-lane transport', function (string $case): void {
    $workspace = sandbox_workspace();
    if ($case === 'shared') {
        $workspace->update(['task_sandbox_id' => null]);
    } else {
        $workspace->project->update(['slug' => 'dlf']);
        $sandbox = $workspace->taskSandbox;
        $sandbox->update(['spec' => [...$sandbox->spec, 'images' => ['gateway' => str_repeat('a', 64)]]]);
    }
    mock(SshExecutor::class)->shouldReceive('execute')->never();

    expect(fn () => app(TaskWorkspaceExecutor::class)->execute($workspace, new RemoteCommand(['id']), 'proof', 'tasks.proof', role: 'gateway'))
        ->toThrow(RuntimeConvergenceException::class, 'requested sandbox role');
})->with(['shared', 'project']);

it('executes in an owned recorded workload guest', function (string $role): void {
    $workspace = sandbox_workspace();
    $workspace->project->update(['default_branch' => 'main']);
    $sandbox = $workspace->taskSandbox;
    $sandbox->update(['spec' => [...$sandbox->spec, 'images' => [$role => str_repeat('a', 64)],
        'source_template' => ['id' => '9862e1aa-605c-4b49-a65b-6cf0b3a96dfe', 'repository' => 'https://github.com/acme/orbit.git', 'base' => 'main', 'commit' => str_repeat('b', 40)]]]);
    mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(function (SshConnection $connection, RemoteCommand $command) use ($role): CommandResult {
        expect($command->arguments)->toBe(['/usr/local/bin/orbit-agent', 'sandbox']);
        $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        expect($request['guest']['role'])->toBe($role);

        return new CommandResult(0, json_encode(['name' => 'ot-0a68f778a3', 'role' => $role, 'exit_code' => 0,
            'stdout' => base64_encode('orbit'), 'stderr' => '', 'duration_ms' => 1, 'truncated' => false, 'timed_out' => false]), '', 1, false);
    });

    expect(app(TaskWorkspaceExecutor::class)->execute($workspace, new RemoteCommand(['id']), 'proof', 'tasks.proof', role: $role)->stdout)->toBe('orbit');
})->with(['app-dev', 'app-prod', 'app-prod-2']);

it('refuses unrecorded and foreign workload roles before any guest command', function (string $fault): void {
    $workspace = sandbox_workspace();
    $sandbox = $workspace->taskSandbox;
    $spec = [...$sandbox->spec, 'images' => ['app-dev' => str_repeat('a', 64)], 'source_template' => ['id' => 'template']];
    match ($fault) {
        'unrecorded' => $spec['images'] = [],
        'malformed image' => $spec['images']['app-dev'] = 'invalid',
        'missing source' => $spec['source_template'] = null,
        'project lane' => $workspace->project->update(['slug' => 'dlf']),
        'foreign provider' => $sandbox->update(['provider' => 'upcloud']),
    };
    $sandbox->update(['spec' => $spec]);
    mock(SshExecutor::class)->shouldReceive('execute')->never();

    expect(fn () => app(TaskWorkspaceExecutor::class)->execute($workspace, new RemoteCommand(['id']), 'proof', 'tasks.proof', role: 'app-dev'))
        ->toThrow(RuntimeConvergenceException::class, 'requested sandbox role');
})->with(['unrecorded', 'malformed image', 'missing source', 'project lane', 'foreign provider']);

it('refuses incomplete or foreign workload source provenance before contacting compute', function (string $fault): void {
    $workspace = sandbox_workspace();
    $workspace->project->update(['default_branch' => 'main']);
    $sandbox = $workspace->taskSandbox;
    $template = ['id' => '9862e1aa-605c-4b49-a65b-6cf0b3a96dfe', 'repository' => 'https://github.com/acme/orbit.git', 'base' => 'main', 'commit' => str_repeat('b', 40)];
    match ($fault) {
        'empty' => $template = [],
        'invalid identity' => $template['id'] = 'foreign',
        'invalid commit' => $template['commit'] = 'invalid',
        'foreign repository' => $template['repository'] = 'https://github.com/acme/foreign.git',
        'foreign base' => $template['base'] = 'another-branch',
        'extra field' => $template['extra'] = 'foreign',
    };
    $sandbox->update(['spec' => [...$sandbox->spec, 'images' => ['app-dev' => str_repeat('a', 64)], 'source_template' => $template]]);
    mock(SshExecutor::class)->shouldReceive('execute')->never();

    expect(fn () => app(TaskWorkspaceExecutor::class)->execute($workspace, new RemoteCommand(['id']), 'proof', 'tasks.proof', role: 'app-dev'))
        ->toThrow(RuntimeConvergenceException::class, 'requested sandbox role');
})->with(['empty', 'invalid identity', 'invalid commit', 'foreign repository', 'foreign base', 'extra field']);
