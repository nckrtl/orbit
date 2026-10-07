<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxNetworkPolicy;
use App\Domain\Compute\SandboxNodeBootstrap;
use App\Domain\Compute\SandboxState;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\Instances\InstanceState;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskCapacityException;
use App\Infrastructure\Compute\TaskSandboxGroupLifecycle;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Infrastructure\Tasks\UpCloudWorkspaceProvisioner;
use App\Models\Instance;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeSandboxModelProxy;
use Tests\Support\UpCloudRuntimeWorkspace;

use function Pest\Laravel\mock;

it('enrolls and prepares one owned workspace through initial admission or cloud recovery, preserving it on retry', function (bool $restore): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $sandbox = $workspace->taskSandbox;
    $group = $sandbox->group;
    $group->taskable()->dissociate();
    $group->update(['status' => 'reserved', 'reserved_at' => now(), 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    $workspace->delete();
    $sandbox->forceFill(['enrolled_at' => null, 'pi_ready_at' => null])->save();
    if ($restore) {
        $sandbox->forceFill(['state' => SandboxState::Destroyed, 'desired_power' => 'destroyed', 'node_id' => null,
            'server_id' => null, 'disk_id' => null, 'pi_token' => null, 'model_key' => null])->save();
        $group->update(['status' => 'running', 'pr_url' => 'https://github.com/acme/dlf/pull/42']);
        GitHubTestSupport::storeApp();
        $github = mock(GitHubApi::class);
        $github->shouldReceive('repositoryInstallation')->once()->andReturn(9);
        $github->shouldReceive('repositoryPullRequestReadToken')->twice()->andReturn('gateway-only-pr-token');
        $github->shouldReceive('pullRequest')->twice()->andReturn(new GitHubPullRequest(GitHubPullRequestState::Open, true, 'clean', str_repeat('d', 40), 'main'));
    }
    $proxy = new FakeSandboxModelProxy;
    $proxy->install();
    app(ProxyCliState::class)->enable(1, 'proof', 'http://10.44.0.3:8317', 'management-proof-secret', 'read', 'control', 8787);
    $proxy->keys = $restore ? [] : [$sandbox->model_key];
    Http::fake(['http://10.44.0.3:8317/*' => $proxy->respond(...)]);
    $file = tmpfile();
    fwrite($file, str_repeat('x', 128));
    config(['compute.project_claims_enabled' => true, 'compute.upcloud.enabled' => true,
        'compute.pi.artifact_path' => stream_get_meta_data($file)['uri'], 'compute.pi.artifact_sha256' => hash('sha256', str_repeat('x', 128)),
        'compute.upcloud.gateway_address' => '93.184.216.34', 'compute.upcloud.wireguard_address' => '93.184.216.35',
        'compute.pi.models' => [['id' => 'probe', 'name' => 'Proof', 'reasoning' => false, 'input' => ['text'], 'contextWindow' => 8192, 'maxTokens' => 1024]]]);
    $driver = mock(ComputeDriver::class);
    if ($restore) {
        $driver->shouldReceive('capacity')->once()->andReturn(1);
    }
    $driver->shouldReceive('provision')->twice()->andReturnUsing(function ($s) use ($restore) {
        if ($restore && $s->state === SandboxState::Reserved) {
            $s->update(['state' => SandboxState::Running, 'server_id' => (string) Str::uuid(), 'disk_id' => (string) Str::uuid(), 'public_address' => '93.184.216.39']);
        }

        return $s;
    });
    $driver->shouldReceive('observe')->once()->andReturnUsing(fn ($s) => $s);
    $driver->shouldReceive('sealNetwork')->once()->andReturnUsing(function ($s) {
        $s->update(['network_policy' => 'sealed']);

        return $s;
    });
    $bootstrap = mock(SandboxNodeBootstrap::class);
    $bootstrap->shouldReceive('prepare')->once();
    $bootstrap->shouldReceive('enroll')->once()->andReturnUsing(function ($s, $n) {
        $n->update(['status' => 'active']);
        $n->roles()->firstOrCreate(['role' => 'app-dev'], ['status' => 'active']);

        return $n;
    });
    mock(SandboxNetworkPolicy::class)->shouldReceive('ensure')->once()->andReturnUsing(function ($s): void {
        $s->forceFill(['enrollment' => [...$s->enrollment, 'hub_confirmed_at' => now()->toIso8601String()]])->save();
    });
    mock(TaskBaseBranchFetcher::class)->shouldReceive('fetchForTurn')->once();
    $keys = mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    if ($restore) {
        $keys->shouldReceive('publicKey')->once()->andReturn($sandbox->spec['public_key']);
    }
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    config(['app.url' => 'https://gateway.orbit']);
    mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->twice()->andReturn('trusted-ca');
    $phases = [];
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function ($connection, RemoteCommand $command) use ($group, $restore, &$phases): CommandResult {
        expect($group->fresh()->taskable->taskSandbox->group_id)->toBe($group->id);
        if ($command->input !== null) {
            $request = json_decode($command->input, true, flags: JSON_THROW_ON_ERROR);
            $phases[] = $request['operation'];
            if ($restore) {
                expect($request['required_commit'])->toBe(str_repeat('d', 40));
            }
            $response = ['initialized' => true, 'starting_commit' => str_repeat('d', 40), 'head' => str_repeat('d', 40)];
        } else {
            $stream = $command->protectedInput->stream();
            $request = json_decode(fgets($stream), true, flags: JSON_THROW_ON_ERROR);
            $phases[] = isset($request['ca']) ? 'github' : (isset($request['sha256']) ? 'artifact' : 'pi');
            $response = ['sandbox_id' => $request['sandbox_id'], 'ready' => true, 'sha256' => $request['sha256'] ?? null];
            if (isset($request['ca'])) {
                expect($request['repository'])->toBe('acme/dlf');
                $response = ['ready' => true];
            }
        }

        return new CommandResult(0, json_encode($response), '', 1, false);
    });
    try {
        $provisioner = app(TaskWorkspaceProvisioner::class);
        if ($restore) {
            $resumed = app(TaskSandboxGroupLifecycle::class)->resume($group);
            expect($group->fresh()->capacity_wait_reason)->toBeNull();
            expect($resumed)->toBeTrue();
            $first = $group->fresh()->taskable;
            $second = app(UpCloudWorkspaceProvisioner::class)->restore($group->fresh());
        } else {
            $first = $provisioner->provision(InstanceProvisionIntent::for($group));
            $second = $provisioner->provision(InstanceProvisionIntent::for($group->fresh()));
        }
        expect($first)->toBeInstanceOf(Instance::class);
        if ($restore) {
            expect($first->task_sandbox_id)->not->toBe($sandbox->id);
            expect($first->taskSandbox->spec['restore_commit'])->toBe(str_repeat('d', 40));
        }
        expect($second->id)->toBe($first->id);
        expect($second->status)->toBe(InstanceState::SourceResolved);
        expect($second->taskSandbox->pi_ready_at)->not->toBeNull();
        expect($phases)->toBe(['initialize', 'checkout', 'artifact', 'pi', 'github', 'inspect', 'artifact', 'pi', 'github']);
        expect(Instance::query()->where('task_sandbox_id', $first->task_sandbox_id)->count())->toBe(1);
    } finally {
        fclose($file);
    }
})->with(['initial claim' => [false], 'recovery claim' => [true]]);

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
    $sandbox->update(['state' => SandboxState::Destroyed, 'desired_power' => 'destroyed', 'node_id' => null,
        'server_id' => $fault === 'cleanup' ? $sandbox->server_id : null, 'disk_id' => null]);
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
        expect(fn () => app(UpCloudWorkspaceProvisioner::class)->restore($group))->toThrow(ComputeException::class);
        expect(TaskSandbox::query()->where('group_id', $group->id)->count())->toBe(1);
        expect($group->fresh()->taskable_id)->toBeNull();
    } finally {
        fclose($file);
    }
})->with(['foreign', 'closed', 'missing-commit', 'api', 'cleanup', 'held']);
