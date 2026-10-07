<?php

declare(strict_types=1);

use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\SandboxNetworkPolicy;
use App\Domain\Compute\SandboxNodeBootstrap;
use App\Domain\Instances\InstanceState;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskCapacityException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Models\Instance;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSandboxModelProxy;
use Tests\Support\UpCloudRuntimeWorkspace;

use function Pest\Laravel\mock;

it('enrolls and attaches one project workspace, then prepares source and Pi, and retries without replacing it', function (): void {
    $workspace = UpCloudRuntimeWorkspace::create();
    $sandbox = $workspace->taskSandbox;
    $group = $sandbox->group;
    $group->taskable()->dissociate();
    $group->update(['status' => 'reserved', 'reserved_at' => now(), 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    $workspace->delete();
    $sandbox->forceFill(['enrolled_at' => null])->save();
    $proxy = new FakeSandboxModelProxy;
    $proxy->install();
    app(ProxyCliState::class)->enable(1, 'proof', 'http://10.44.0.3:8317', 'management-proof-secret', 'read', 'control', 8787);
    $proxy->keys = [$sandbox->model_key];
    Http::fake(['http://10.44.0.3:8317/*' => $proxy->respond(...)]);
    $file = tmpfile();
    fwrite($file, str_repeat('x', 128));
    config(['compute.project_claims_enabled' => true, 'compute.upcloud.enabled' => true,
        'compute.pi.artifact_path' => stream_get_meta_data($file)['uri'], 'compute.pi.artifact_sha256' => hash('sha256', str_repeat('x', 128)),
        'compute.pi.models' => [['id' => 'probe', 'name' => 'Proof', 'reasoning' => false, 'input' => ['text'], 'contextWindow' => 8192, 'maxTokens' => 1024]]]);
    $driver = mock(ComputeDriver::class);
    $driver->shouldReceive('provision')->twice()->andReturnUsing(fn ($s) => $s);
    $driver->shouldReceive('observe', 'sealNetwork')->once()->andReturnUsing(fn ($s) => $s);
    $bootstrap = mock(SandboxNodeBootstrap::class);
    $bootstrap->shouldReceive('prepare')->once();
    $bootstrap->shouldReceive('enroll')->once()->andReturnUsing(fn ($s, $n) => $n);
    mock(SandboxNetworkPolicy::class)->shouldReceive('ensure')->once();
    mock(TaskBaseBranchFetcher::class)->shouldReceive('fetchForTurn')->once();
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    $phases = [];
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function ($connection, RemoteCommand $command) use ($group, $sandbox, &$phases): CommandResult {
        expect($group->fresh()->taskable->task_sandbox_id)->toBe($sandbox->id);
        if ($command->input !== null) {
            $request = json_decode($command->input, true, flags: JSON_THROW_ON_ERROR);
            $phases[] = $request['operation'];
            $response = ['initialized' => true, 'starting_commit' => str_repeat('d', 40), 'head' => str_repeat('d', 40)];
        } else {
            $stream = $command->protectedInput->stream();
            $request = json_decode(fgets($stream), true, flags: JSON_THROW_ON_ERROR);
            $phases[] = isset($request['sha256']) ? 'artifact' : 'pi';
            $response = ['sandbox_id' => $sandbox->id, 'ready' => true, 'sha256' => $request['sha256'] ?? null];
        }

        return new CommandResult(0, json_encode($response), '', 1, false);
    });
    try {
        $provisioner = app(TaskWorkspaceProvisioner::class);
        $first = $provisioner->provision(InstanceProvisionIntent::for($group));
        $second = $provisioner->provision(InstanceProvisionIntent::for($group->fresh()));
        expect($first)->toBeInstanceOf(Instance::class);
        expect($second->id)->toBe($first->id);
        expect($second->status)->toBe(InstanceState::SourceResolved);
        expect($second->taskSandbox->pi_ready_at)->not->toBeNull();
        expect($phases)->toBe(['initialize', 'checkout', 'artifact', 'pi', 'inspect', 'artifact', 'pi']);
        expect(Instance::query()->where('task_sandbox_id', $sandbox->id)->count())->toBe(1);
    } finally {
        fclose($file);
    }
});

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
