<?php

declare(strict_types=1);

use App\Domain\Compute\ComputeException;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\TaskCompute;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\Pi\PiModel;
use App\Infrastructure\Tasks\SandboxPiRuntime;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

function pi_runtime_group(): Task
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

it('configures only the owning guest through protected input and requires an identity matching response', function (bool $valid): void {
    $group = pi_runtime_group();
    $workspace = $group->taskable;
    $sandbox = $workspace->taskSandbox;
    $sandbox->forceFill(['pi_token' => str_repeat('a', 64), 'model_key' => str_repeat('b', 64), 'model_key_registered_at' => now()])->save();
    if ($valid) {
        $sandbox->forceFill(['model_proxy_origin' => 'http://10.44.0.3:8317',
            'spec' => [...$sandbox->spec, 'subnet' => '10.233.201.0/24', 'model_proxy_origin' => 'http://10.44.0.3:8317'],
        ])->save();
    }
    mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(function ($connection, RemoteCommand $command) use ($sandbox, $valid): CommandResult {
        expect($command->input)->toBeNull()->and($command->arguments)->toBe(['/usr/local/bin/orbit-agent', 'sandbox']);
        $envelope = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        $guest = $envelope['guest'];
        expect($envelope['sandbox_id'])->toBe($sandbox->id)->and($guest['role'])->toBe('operator');
        expect(implode(' ', $guest['argv']))->not->toContain($sandbox->pi_token, $sandbox->model_key);
        $request = json_decode(base64_decode($guest['stdin']), true, flags: JSON_THROW_ON_ERROR);
        expect($request['model_relay_address'])->toBe($valid ? '10.233.201.1' : null);
        expect($request['pi_token'])->toBe($sandbox->pi_token)->and($request['model_key'])->toBe($sandbox->model_key);
        $response = $valid ? json_encode(['sandbox_id' => $sandbox->id, 'ready' => true]) : $sandbox->model_key;

        return new CommandResult(0, json_encode(['name' => 'ot-0a68f778a3', 'role' => 'operator', 'exit_code' => 0,
            'stdout' => base64_encode($response), 'stderr' => '', 'duration_ms' => 1, 'truncated' => false, 'timed_out' => false]), '', 1, false);
    });
    if ($valid) {
        app(SandboxPiRuntime::class)->prepare($workspace);
    } else {
        try {
            app(SandboxPiRuntime::class)->prepare($workspace);
            test()->fail('Invalid runtime output must fail.');
        } catch (ComputeException $error) {
            expect($error->getPrevious())->toBeNull()->and($error->getMessage())->not->toContain($sandbox->model_key);
        }
    }
})->with([true, false]);

it('refuses guest preparation without live confirmed model credentials', function (string $fault): void {
    $group = pi_runtime_group();
    if ($fault !== 'missing') {
        $group->taskable->taskSandbox->forceFill(['pi_token' => str_repeat('a', 64), 'model_key' => str_repeat('b', 64), 'model_key_registered_at' => now(),
            ...($fault === 'revoked' ? ['model_key_revoked_at' => now()] : ['desired_power' => 'stopped']),
        ])->save();
    }
    mock(SshExecutor::class)->shouldReceive('execute')->never();

    expect(fn () => app(SandboxPiRuntime::class)->prepare($group->taskable))->toThrow(ComputeException::class, 'registered credentials');
})->with(['missing', 'revoked', 'stopped']);

it('always selects the sandbox proxy provider and still refuses Claude', function (): void {
    expect(PiModel::forSandbox('gpt-5.6-luna'))->toBe('orbit-sandbox/gpt-5.6-luna');
    expect(PiModel::forSandbox('openai-codex/gpt-5.6-luna'))->toBe('orbit-sandbox/gpt-5.6-luna');
    expect(fn () => PiModel::forSandbox('anthropic/claude-opus'))->toThrow(AgentDriverException::class);
});

it('permits empty Pi auth storage while refusing subscription credentials and model endpoint overrides', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/Compute/guest_pi_runtime_test.py'), resource_path('compute/guest-pi-runtime.py')]);
    $process->mustRun();
    expect($process->getExitCode())->toBe(0);
});

it('refuses a model relay outside its reserved group subnet before guest transport', function (): void {
    $group = pi_runtime_group();
    $group->taskable->taskSandbox->forceFill(['pi_token' => str_repeat('a', 64), 'model_key' => str_repeat('b', 64),
        'model_key_registered_at' => now(), 'model_proxy_origin' => 'http://10.44.0.3:8317',
        'spec' => ['subnet' => '10.44.0.0/16', 'model_proxy_origin' => 'http://10.44.0.3:8317'],
    ])->save();
    mock(SshExecutor::class)->shouldReceive('execute')->never();

    expect(fn () => app(SandboxPiRuntime::class)->prepare($group->taskable))->toThrow(ComputeException::class, 'did not confirm readiness');
});
