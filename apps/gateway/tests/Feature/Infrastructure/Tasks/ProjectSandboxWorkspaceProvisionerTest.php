<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxNetworkPolicy;
use App\Domain\Compute\SandboxState;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCapacityException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\ProjectSandboxWorkspaceProvisioner;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Models\TaskSandbox;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\IncusRuntimeWorkspace;
use Tests\Support\UpCloudRuntimeWorkspace;

use function Pest\Laravel\mock;

it('does not admit a project with the rollout switch off or a stale claim', function (string $fault): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $group = $workspace->taskSandbox->group;
    $group->update(['status' => 'reserved', 'reserved_at' => now()]);
    $reserved = $group->fresh();
    if ($fault === 'stale') {
        $group->update(['reserved_at' => now()->addSecond()]);
    }
    mock(ComputeDriver::class)->shouldNotReceive('provision');
    mock(SshExecutor::class)->shouldNotReceive('execute');

    expect(fn () => app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($reserved)))->toThrow(TaskCapacityException::class);
    expect($group->fresh()->taskable_id)->toBe($workspace->id);
})->with(['disabled', 'stale']);

it('refuses recovery before allocation when publication or cleanup cannot be confirmed', function (string $fault): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $sandbox = $workspace->taskSandbox;
    $group = $sandbox->group;
    $group->taskable()->dissociate();
    $group->update(['status' => 'running', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'pr_url' => $fault === 'foreign' ? 'https://github.com/acme/foreign/pull/42' : 'https://github.com/acme/dlf/pull/42']);
    $workspace->delete();
    $sandbox->forceFill(['state' => SandboxState::Destroyed, 'desired_power' => $fault === 'power' ? 'running' : 'destroyed',
        'node_id' => null, 'destroyed_at' => $fault === 'cleanup' ? null : now(),
        'model_key' => $fault === 'model key' ? $sandbox->model_key : null,
        'pi_token' => $fault === 'pi token' ? $sandbox->pi_token : null])->save();
    if ($fault !== 'peer') {
        $workspace->node->roles()->delete();
        $workspace->node->delete();
    }
    if ($fault === 'held') {
        $group->update(['watched_pr_completion' => 'merged']);
    }
    $file = tmpfile();
    fwrite($file, str_repeat('x', 128));
    config(['compute.project_claims_enabled' => true, 'compute.upcloud.enabled' => true, 'compute.model_proxy.enabled' => true,
        'compute.pi.artifact_path' => stream_get_meta_data($file)['uri'], 'compute.pi.artifact_sha256' => hash('sha256', str_repeat('x', 128)),
        'compute.pi.models' => [['id' => 'probe']]]);
    mock(ComputeDriver::class)->shouldNotReceive('capacity', 'provision');
    mock(SshExecutor::class)->shouldNotReceive('execute');
    if (in_array($fault, ['closed', 'missing-commit', 'api'], true)) {
        GitHubTestSupport::storeApp();
        $github = mock(GitHubApi::class);
        $github->shouldReceive('repositoryInstallation')->once()->andReturn(9);
        $github->shouldReceive('repositoryPullRequestReadToken')->once()->andReturn('gateway-only-token');
        $api = $github->shouldReceive('pullRequest')->once();
        if ($fault === 'api') {
            $api->andThrow(new RuntimeException('private-api-response'));
        } else {
            $api->andReturn(new GitHubPullRequest($fault === 'closed' ? GitHubPullRequestState::Closed : GitHubPullRequestState::Open,
                null, null, $fault === 'missing-commit' ? null : str_repeat('d', 40), 'main'));
        }
    } else {
        mock(GitHubApi::class)->shouldNotReceive('pullRequest');
    }
    try {
        expect(fn () => app(ProjectSandboxWorkspaceProvisioner::class)->restore($group))->toThrow(ComputeException::class);
        expect(TaskSandbox::query()->where('group_id', $group->id)->count())->toBe(1);
        expect($group->fresh()->taskable_id)->toBeNull();
    } finally {
        fclose($file);
    }
})->with(['foreign', 'closed', 'missing-commit', 'api', 'cleanup', 'power', 'model key', 'pi token', 'peer', 'held']);

it('refreshes local runtime through the same owned guest after park and preview reactivation', function (): void {
    $workspace = IncusRuntimeWorkspace::create();
    $group = $workspace->taskSandbox->group;
    $group->update(['status' => 'running']);
    $workspace->update(['status' => 'source_resolved']);
    $file = tmpfile();
    fwrite($file, str_repeat('x', 128));
    config(['app.url' => 'https://gateway.orbit', 'compute.pi.artifact_path' => stream_get_meta_data($file)['uri'],
        'compute.pi.artifact_sha256' => hash('sha256', str_repeat('x', 128)), 'compute.pi.models' => [['id' => 'probe']]]);
    $keys = mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    $keys->shouldReceive('publicKey')->once()->andReturn(IncusRuntimeWorkspace::key()->type.' '.IncusRuntimeWorkspace::key()->value);
    $hosts = mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/keys/known_hosts');
    $hosts->shouldReceive('put')->twice();
    mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->once()->andReturn('trusted-ca');
    $phases = [];
    mock(SandboxNetworkPolicy::class)->shouldReceive('ensure')->once()->andReturnUsing(function () use (&$phases): void {
        $phases[] = 'hub';
    });
    mock(SshExecutor::class)->shouldReceive('execute')->times(5)->andReturnUsing(function ($connection, RemoteCommand $command) use ($workspace, &$phases): CommandResult {
        $request = json_decode(fgets($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        if (isset($request['operation'])) {
            expect($connection->host)->toBe('10.44.0.20');
            if ($request['operation'] === 'guest_command') {
                expect($request['guest']['role'])->toBe('operator');
                expect($request['guest']['argv'])->toBe(['sudo', '-n', 'python3', '-I', '-c', file_get_contents(resource_path('compute/guest-project-ssh.py'))]);
                $bootstrap = json_decode(base64_decode($request['guest']['stdin']), true);
                expect($bootstrap['gateway_time'])->toMatch('/\A[0-9]{10}\.[0-9]{6}\z/D');
                unset($bootstrap['gateway_time']);
                expect($bootstrap)->toBe([
                    'public_key' => IncusRuntimeWorkspace::key()->type.' '.IncusRuntimeWorkspace::key()->value,
                ]);
                $phases[] = 'bootstrap';
                $result = ['name' => $workspace->taskSandbox->name, 'role' => 'operator', 'exit_code' => 0,
                    'stdout' => base64_encode(json_encode(['ready' => true])), 'stderr' => base64_encode(''), 'duration_ms' => 1, 'truncated' => false, 'timed_out' => false];
            } else {
                expect($request['operation'])->toBe('project_fleet_identity');
                $phases[] = 'identity';
                $result = ['name' => $workspace->taskSandbox->name, 'guest' => $workspace->taskSandbox->name.'-operator', 'project_slug' => 'dlf', 'image' => str_repeat('a', 64), 'pool' => 'proof',
                    'subnet' => '10.233.201.0/24', 'address' => '10.233.201.10', 'ssh_key' => IncusRuntimeWorkspace::key()->type.' '.IncusRuntimeWorkspace::key()->value];
            }
        } else {
            expect($connection->host)->toBe($workspace->node->wireguard_ip);
            $phase = isset($request['sha256']) ? 'artifact' : (isset($request['ca']) ? 'github' : 'pi');
            $phases[] = $phase;
            if ($phase === 'pi') {
                expect($request['model_relay_kind'])->toBe('fleet');
                expect($request['model_relay_address'])->toBe('10.44.0.3');
                expect($request['pi_ingress'])->toBeNull();
            }
            $result = $phase === 'github' ? ['ready' => true] : ['sandbox_id' => $workspace->task_sandbox_id, 'ready' => true, 'sha256' => $request['sha256'] ?? null];
        }

        return new CommandResult(0, json_encode($result), '', 1, false);
    });
    try {
        $result = app(ProjectSandboxWorkspaceProvisioner::class)->resumeLocal($group);
        expect($result->id)->toBe($workspace->id);
        expect($result->taskSandbox->pi_ready_at)->not->toBeNull();
        expect($phases)->toBe(['identity', 'bootstrap', 'hub', 'artifact', 'pi', 'github']);
    } finally {
        fclose($file);
    }
});
