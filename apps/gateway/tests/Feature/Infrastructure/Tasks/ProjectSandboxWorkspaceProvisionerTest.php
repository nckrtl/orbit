<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxNetworkPolicy;
use App\Domain\Compute\SandboxNodeBootstrap;
use App\Domain\Compute\SandboxSpec;
use App\Domain\Compute\SandboxState;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\InstanceState;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Tasks\InstanceProvisionFailure;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskCapacityException;
use App\Infrastructure\Compute\TaskSandboxGroupLifecycle;
use App\Infrastructure\Compute\UpCloudCloudInit;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\ProjectSandboxWorkspaceProvisioner;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Models\Instance;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeSandboxModelProxy;
use Tests\Support\IncusRuntimeWorkspace;
use Tests\Support\UpCloudRuntimeWorkspace;

use function Pest\Laravel\mock;

it('enrolls and prepares one owned workspace through initial admission or cloud recovery, preserving it on retry', function (bool $restore, bool $web): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $sandbox = $workspace->taskSandbox;
    $group = $sandbox->group;
    $group->project->update(['type' => $web ? 'laravel-app' : 'laravel-package', 'root' => $web ? 'public' : null]);
    $group->taskable()->dissociate();
    $group->update(['status' => 'reserved', 'reserved_at' => now(), 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    $workspace->delete();
    $sandbox->forceFill(['enrolled_at' => null, 'pi_ready_at' => null])->save();
    if ($restore) {
        $sandbox->forceFill(['state' => SandboxState::Destroyed, 'desired_power' => 'destroyed', 'node_id' => null,
            'destroyed_at' => now(), 'pi_token' => null, 'model_key' => null])->save();
        $workspace->node->roles()->delete();
        $workspace->node->delete();
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
    if ($restore) {
        $driver->shouldReceive('provision')->twice()->andReturnUsing(function ($s) {
            if ($s->state === SandboxState::Reserved) {
                $s->update(['state' => SandboxState::Running, 'server_id' => (string) Str::uuid(), 'disk_id' => (string) Str::uuid(), 'public_address' => '93.184.216.39']);
            }

            return $s;
        });
    } else {
        $tokenPath = tempnam(sys_get_temp_dir(), 'project-upcloud-token-');
        file_put_contents($tokenPath, "token: ucat_test_only\n");
        chmod($tokenPath, 0600);
        $this->beforeApplicationDestroyed(static fn () => unlink($tokenPath));
        config(['compute.upcloud.token_file' => $tokenPath]);
        $sandbox->update(['create_attempted_at' => now(), 'credential_fingerprint' => hash('sha256', 'ucat_test_only')]);
        $server = json_decode(file_get_contents(base_path('tests/Fixtures/Compute/upcloud-server.json')), true, flags: JSON_THROW_ON_ERROR)['server'];
        $server['uuid'] = $sandbox->server_id;
        $server['title'] = $server['hostname'] = $sandbox->name;
        $server['labels']['label'] = [['key' => 'orbit-sandbox', 'value' => $sandbox->id]];
        $server['storage_devices']['storage_device'][0]['storage'] = $sandbox->disk_id;
        $server['storage_devices']['storage_device'][0]['storage_title'] = $sandbox->name.'-disk';
        $server['ip_addresses']['ip_address'][0]['address'] = $sandbox->public_address;
        Http::fake([
            'https://api.upcloud.com/1.3/server/'.$sandbox->server_id => Http::response(['server' => $server]),
            'https://api.upcloud.com/1.3/server/'.$sandbox->server_id.'/firewall_rule' => Http::response([
                'firewall_rules' => ['firewall_rule' => app(UpCloudCloudInit::class)->firewall(SandboxSpec::fromArray($sandbox->spec), true)],
            ]),
        ]);
    }
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
    $environmentWrites = [];
    if ($web) {
        $configuration = mock(DevelopmentInstanceConfigurator::class);
        $configuration->shouldReceive('inspect')->once()->andReturn(new DevelopmentSourceProfile('8.5', true));
        $configuration->shouldReceive('configureLaravelUrl')->once();
        mock(DevelopmentRouteProjector::class)->shouldReceive('converge')->once();
        // One import read, and one key check before each of the two synchronizations.
        mock(InstanceEnvironmentReader::class)->shouldReceive('read')->times(3)->andReturn("APP_KEY=\nAPP_URL=https://template.invalid\n");
        $preflight = mock(InstanceOperationPreflight::class);
        $preflight->shouldReceive('assertEnvironmentWritable')->twice();
        mock(InstanceEnvironmentWriter::class)->shouldReceive('write')->twice()
            ->andReturnUsing(function ($context, string $contents) use (&$environmentWrites): InstanceEnvironmentWriteResult {
                $environmentWrites[] = $contents;

                return InstanceEnvironmentWriteResult::changed();
            });
    }
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
            $second = app(ProjectSandboxWorkspaceProvisioner::class)->restore($group->fresh());
        } else {
            $first = $provisioner->provision(InstanceProvisionIntent::for($group));
            $this->assertInstanceOf(Instance::class, $first, $first instanceof InstanceProvisionFailure ? $first->cause : '');
            $second = $provisioner->provision(InstanceProvisionIntent::for($group->fresh()));
        }
        expect($first)->toBeInstanceOf(Instance::class);
        if ($restore) {
            expect($first->task_sandbox_id)->not->toBe($sandbox->id);
            expect($first->taskSandbox->spec['restore_commit'])->toBe(str_repeat('d', 40));
            expect($sandbox->fresh()->server_id)->toBe($sandbox->server_id);
            expect($sandbox->fresh()->disk_id)->toBe($sandbox->disk_id);
            expect($sandbox->server_id)->not->toBeNull();
            expect($sandbox->disk_id)->not->toBeNull();
        }
        expect($second->id)->toBe($first->id);
        expect($second->status)->toBe($web ? InstanceState::Active : InstanceState::SourceResolved);
        expect($second->node->accessibleNodes()->pluck('nodes.id')->all())->toBe([$second->node_id]);
        expect($second->taskSandbox->pi_ready_at)->not->toBeNull();
        expect($phases)->toBe(['initialize', 'checkout', 'artifact', 'pi', 'github', 'inspect', 'artifact', 'pi', 'github']);
        if ($web) {
            expect($environmentWrites)->toHaveCount(2);
            expect($environmentWrites[1])->toBe($environmentWrites[0]);
            expect($environmentWrites[0])->toContain('APP_KEY="base64:', 'https://'.$second->name.'.dlf.test');
            expect($second->routes()->count())->toBe(1);
        }
        expect(Instance::query()->where('task_sandbox_id', $first->task_sandbox_id)->count())->toBe(1);
    } finally {
        fclose($file);
    }
})->with(['initial source claim' => [false, false], 'cloud source recovery' => [true, false], 'initial private preview' => [false, true], 'cloud private preview recovery' => [true, true]]);

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
                expect(json_decode(base64_decode($request['guest']['stdin']), true))->toBe([
                    'public_key' => IncusRuntimeWorkspace::key()->type.' '.IncusRuntimeWorkspace::key()->value,
                    'recovery_port' => null,
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
