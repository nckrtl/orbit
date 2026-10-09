<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskPullRequestException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\GitHubTaskBaseBranchFetcher;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Infrastructure\Tasks\SandboxGitBundles;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Tests\Feature\GitHub\GitHubTestSupport;

use function Pest\Laravel\mock;

function bundle_workspace(): Instance
{
    $host = Node::query()->create(['name' => 'compute', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'user' => 'managed']);
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git', 'apps' => fixture_apps(null)]);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Bundle', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
    $sandbox = TaskSandbox::query()->create(['id' => 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 'group_id' => $group->id,
        'provider' => 'incus', 'name' => 'ot-0a68f778a3', 'state' => 'running', 'desired_power' => 'running',
        'spec' => ['host_id' => $host->id, 'project' => 'orbit-task-sandboxes']]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $host->id, 'name' => 'task-'.$group->id,
        'checkout_path' => '/home/orbit/orbit', 'task_sandbox_id' => $sandbox->id]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);
    config(['compute.incus.hosts' => [['node_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'pool' => 'proof', 'max_vms' => 4,
        'orbit_images' => [], 'project_images' => [], 'blocked_networks' => ['192.168.0.0/16']]]]);

    return $workspace;
}

function bundle_guest(callable $callback): void
{
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function ($connection, RemoteCommand $command) use ($callback): CommandResult {
        expect($command->arguments)->toBe(['/usr/local/bin/orbit-agent', 'sandbox']);
        $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        expect(json_encode($request).base64_decode($request['guest']['stdin']))->not->toContain('repository-secret', base64_encode('x-access-token:repository-secret'));
        $value = $callback(json_decode(base64_decode($request['guest']['stdin']), true, flags: JSON_THROW_ON_ERROR));
        $body = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);

        return new CommandResult(0, json_encode(['name' => 'ot-0a68f778a3', 'role' => 'operator', 'exit_code' => 0,
            'stdout' => base64_encode($body), 'stderr' => '', 'duration_ms' => 1, 'truncated' => false, 'timed_out' => false], JSON_THROW_ON_ERROR), '', 1, false);
    });
}

function bundle_repository_access(): void
{
    GitHubTestSupport::storeApp();
    $github = mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->once()->andReturn(9);
    $github->shouldReceive('repositoryPullRequestToken')->once()->andReturn('repository-secret');
}

beforeEach(function (): void {
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
});

describe('sandbox bundle broker', function (): void {
    it('moves guest objects to the trusted publisher while keeping its token outside the VM', function (): void {
        $workspace = bundle_workspace();
        $bytes = "disposable bundle\0bytes";
        $operations = [];
        bundle_guest(function (array $request) use ($bytes, &$operations): array|string {
            $operations[] = $request['operation'];

            return match ($request['operation']) {
                'export' => ['size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)],
                'read' => $bytes,
                'remove' => ['removed' => true],
            };
        });
        $path = null;
        mock(ProcessRunner::class)->shouldReceive('run')->once()->andReturnUsing(function (ProcessInvocation $invocation) use ($workspace, $bytes, &$path): CommandResult {
            expect($invocation->arguments)->toBe(['python3', '-I', resource_path('compute/trusted-git-bundle.py')])->and($invocation->input)->toBeNull();
            $request = json_decode(stream_get_contents($invocation->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            $path = $request['bundle'];
            expect($request['token'])->toBe('repository-secret')->and(file_get_contents($path))->toBe($bytes)
                ->and(fileperms(dirname($path)) & 0777)->toBe(0700)->and(fileperms($path) & 0777)->toBe(0600);

            return new CommandResult(0, json_encode(['commit' => str_repeat('a', 40), 'branch' => 'task-'.$workspace->taskSandbox->group_id], JSON_THROW_ON_ERROR), '', 1, false);
        });
        app(SandboxGitBundles::class)->publish($workspace, GitHubRepository::fromOrigin('https://github.com/acme/orbit.git'), $workspace->taskSandbox->group_id, str_repeat('a', 40), 'repository-secret');
        expect($operations)->toBe(['export', 'read', 'remove'])->and(file_exists($path))->toBeFalse()->and(is_dir(dirname($path)))->toBeFalse();
    });

    it('rejects a changed transfer before publishing and cleans both sides', function (): void {
        $workspace = bundle_workspace();
        $removed = false;
        bundle_guest(function (array $request) use (&$removed): array|string {
            if ($request['operation'] === 'remove') {
                $removed = true;

                return ['removed' => true];
            }

            return $request['operation'] === 'export' ? ['size' => 3, 'sha256' => hash('sha256', 'yes')] : 'bad';
        });
        mock(ProcessRunner::class)->shouldReceive('run')->never();
        expect(fn () => app(SandboxGitBundles::class)->publish($workspace, GitHubRepository::fromOrigin('https://github.com/acme/orbit.git'), $workspace->taskSandbox->group_id, str_repeat('a', 40), 'repository-secret'))
            ->toThrow(TaskPullRequestException::class);
        expect($removed)->toBeTrue();
    });

    it('fetches outside the VM and imports only the requested remote tracking ref', function (): void {
        $workspace = bundle_workspace();
        $path = null;
        $bytes = str_repeat('pack', 70000);
        mock(ProcessRunner::class)->shouldReceive('run')->once()->andReturnUsing(function (ProcessInvocation $invocation) use (&$path, $bytes): CommandResult {
            $request = json_decode(stream_get_contents($invocation->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            expect($request['environment'])->toBe(GitReadEnvironment::forGitHubToken('repository-secret')->variables)
                ->and($request['operation'])->toBe('fetch')->and($request['branch'])->toBe('main');
            $path = $request['bundle'];
            file_put_contents($path, $bytes);

            return new CommandResult(0, json_encode(['commit' => str_repeat('a', 40), 'size' => strlen($bytes)], JSON_THROW_ON_ERROR), '', 1, false);
        });
        $received = '';
        $operations = [];
        bundle_guest(function (array $request) use (&$received, &$operations): array {
            $operations[] = $request['operation'];
            if ($request['operation'] === 'write') {
                expect($request['offset'])->toBe(strlen($received));
                $received .= base64_decode($request['data']);

                return ['offset' => strlen($received)];
            }
            if ($request['operation'] === 'import') {
                expect($request['ref'])->toBe('refs/remotes/origin/main')->and($request['commit'])->toBe(str_repeat('a', 40));

                return ['commit' => $request['commit'], 'ref' => $request['ref']];
            }

            return $request['operation'] === 'begin' ? ['offset' => 0] : ['removed' => true];
        });
        app(SandboxGitBundles::class)->fetch($workspace, GitHubRepository::fromOrigin('https://github.com/acme/orbit.git'), 'main', GitReadEnvironment::forGitHubToken('repository-secret'));
        expect($received)->toBe($bytes)->and($operations)->toBe(['begin', 'write', 'write', 'import', 'remove'])->and(file_exists($path))->toBeFalse();
    });

    it('refuses a VM group with missing ownership before obtaining or transporting credentials', function (): void {
        $workspace = bundle_workspace();
        $group = $workspace->taskSandbox->group;
        $workspace->update(['task_sandbox_id' => null]);
        $group->project->update(['default_branch' => 'main']);
        mock(GitHubApi::class)->shouldReceive('repositoryInstallation')->never();
        mock(SshExecutor::class)->shouldReceive('execute')->never();
        mock(ProcessRunner::class)->shouldReceive('run')->never();
        expect(fn () => app(GitHubTaskPullRequestPublisher::class)->push($group, str_repeat('a', 40)))->toThrow(TaskPullRequestException::class)
            ->and(fn () => app(GitHubTaskBaseBranchFetcher::class)->fetch($group, 'main'))->toThrow(TaskPullRequestException::class)
            ->and(fn () => app(GitHubTaskBaseBranchFetcher::class)->fetchForTurn($group))->toThrow(TaskPullRequestException::class);
    });

});
