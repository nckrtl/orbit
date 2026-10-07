<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskPullRequestException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\SandboxWorkspaceSource;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

function source_group(): Task
{
    $host = Node::query()->create(['name' => 'compute', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20']);
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Source', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
    $sandbox = TaskSandbox::query()->create(['id' => 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 'group_id' => $group->id,
        'provider' => 'incus', 'name' => 'ot-0a68f778a3', 'state' => 'running', 'desired_power' => 'running',
        'spec' => ['host_id' => $host->id, 'project' => 'orbit-task-sandboxes']]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $host->id, 'name' => 'task-'.$group->id,
        'checkout_path' => '/home/orbit/orbit', 'task_sandbox_id' => $sandbox->id]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);
    config(['compute.incus.hosts' => [['node_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'pool' => 'proof', 'max_vms' => 4,
        'orbit_images' => [], 'project_images' => [], 'blocked_networks' => ['192.168.0.0/16']]]]);

    return $group;
}

beforeEach(function (): void {
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
});

describe('sandbox source preparation', function (): void {
    it('preserves local work on retry and refuses foreign checkout ownership', function (): void {
        $process = new Process(['python3', base_path('tests/Fixtures/Compute/guest_source_test.py'), resource_path('compute/guest-workspace-source.py')]);
        $process->mustRun();
        expect($process->getExitCode())->toBe(0);
    });

    it('claims an empty root owned volume without adopting a populated directory', function (): void {
        $process = new Process(['python3', base_path('tests/Fixtures/Compute/guest_source_test.py'), resource_path('compute/guest-workspace-source.py')], env: ['ORBIT_TEST_ROOT_VOLUME' => '1']);
        $process->mustRun();
        expect($process->getExitCode())->toBe(0);
    })->group('privileged');

    it('initializes before trusted fetching and records source readiness only after checkout succeeds', function (): void {
        $group = source_group();
        $operations = [];
        mock(SshExecutor::class)->shouldReceive('execute')->times(3)->andReturnUsing(function ($connection, RemoteCommand $command) use (&$operations): CommandResult {
            expect($command->arguments)->toBe(['/usr/local/bin/orbit-agent', 'sandbox']);
            $envelope = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            $request = json_decode(base64_decode($envelope['guest']['stdin']), true, flags: JSON_THROW_ON_ERROR);
            $operations[] = $request['operation'];
            expect($request['repository'])->toBe('https://github.com/acme/orbit.git');
            $response = $request['operation'] === 'initialize' ? ['initialized' => true] : ['head' => str_repeat('a', 40), 'starting_commit' => str_repeat('b', 40)];

            return new CommandResult(0, json_encode(['name' => 'ot-0a68f778a3', 'role' => 'operator', 'exit_code' => 0,
                'stdout' => base64_encode(json_encode($response)), 'stderr' => '', 'duration_ms' => 1, 'truncated' => false, 'timed_out' => false]), '', 1, false);
        });
        mock(TaskBaseBranchFetcher::class)->shouldReceive('fetchForTurn')->once()->andReturnUsing(function (Task $fetching) use (&$operations): void {
            expect($operations)->toBe(['initialize']);
            expect($fetching->taskable->status)->toBe(InstanceState::Reserved);
        });

        $prepared = app(SandboxWorkspaceSource::class)->prepare($group);
        $again = app(SandboxWorkspaceSource::class)->prepare($group->fresh());

        expect($prepared->status)->toBe(InstanceState::SourceResolved)->and($prepared->starting_commit)->toBe(str_repeat('b', 40));
        expect($again->starting_commit)->toBe($prepared->starting_commit);
        expect($operations)->toBe(['initialize', 'checkout', 'inspect']);
    });

    it('does not contact the guest or obtain repository credentials without sandbox ownership', function (): void {
        $group = source_group();
        $group->taskable->update(['task_sandbox_id' => null]);
        mock(SshExecutor::class)->shouldReceive('execute')->never();
        mock(TaskBaseBranchFetcher::class)->shouldReceive('fetchForTurn')->never();

        expect(fn () => app(SandboxWorkspaceSource::class)->prepare($group->fresh()))->toThrow(TaskPullRequestException::class);
    });
});

it('sends only the reservation template through source initialization and inspection', function (): void {
    $group = source_group();
    $template = ['id' => '9862e1aa-605c-4b49-a65b-6cf0b3a96dfe', 'repository' => 'https://github.com/acme/orbit.git', 'base' => 'main', 'commit' => str_repeat('c', 40)];
    $sandbox = $group->taskable->taskSandbox;
    $sandbox->update(['spec' => [...$sandbox->spec, 'source_template' => $template]]);
    mock(SshExecutor::class)->shouldReceive('execute')->times(3)->andReturnUsing(function ($connection, RemoteCommand $command) use ($template): CommandResult {
        $envelope = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        $request = json_decode(base64_decode($envelope['guest']['stdin']), true, flags: JSON_THROW_ON_ERROR);
        expect($request['source_template'])->toBe($template);
        $response = $request['operation'] === 'initialize' ? ['initialized' => true] : ['head' => str_repeat('a', 40), 'starting_commit' => str_repeat('a', 40)];

        return new CommandResult(0, json_encode(['name' => 'ot-0a68f778a3', 'role' => 'operator', 'exit_code' => 0,
            'stdout' => base64_encode(json_encode($response)), 'stderr' => '', 'duration_ms' => 1, 'truncated' => false, 'timed_out' => false]), '', 1, false);
    });
    mock(TaskBaseBranchFetcher::class)->shouldReceive('fetchForTurn')->once();

    $source = app(SandboxWorkspaceSource::class);
    expect($source->prepare($group->fresh())->starting_commit)->toBe(str_repeat('a', 40));
    expect($source->prepare($group->fresh())->status)->toBe(InstanceState::SourceResolved);
});

it('refuses a template from another Project before guest contact or fetching credentials', function (): void {
    $group = source_group();
    $sandbox = $group->taskable->taskSandbox;
    $sandbox->update(['spec' => [...$sandbox->spec, 'source_template' => ['repository' => 'https://github.com/acme/foreign.git', 'base' => 'main']]]);
    mock(SshExecutor::class)->shouldReceive('execute')->never();
    mock(TaskBaseBranchFetcher::class)->shouldReceive('fetchForTurn')->never();

    expect(fn () => app(SandboxWorkspaceSource::class)->prepare($group->fresh()))->toThrow(TaskPullRequestException::class);
    expect($group->taskable->fresh()->status)->toBe(InstanceState::Reserved);
});
