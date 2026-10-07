<?php

declare(strict_types=1);

use App\Domain\Compute\ComputeException;
use App\Domain\Tasks\TaskCompute;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\SandboxPairRuntime;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

/** @return array{Instance, object{calls: list<string>, fail: string|null, endpoint: string, changedHead: bool}} */
function runtime_pair(): array
{
    $host = Node::query()->create(['name' => 'compute', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'user' => 'orbit']);
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Pair', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
    $images = ['operator' => str_repeat('a', 64), 'gateway' => str_repeat('b', 64)];
    $sandbox = TaskSandbox::query()->create(['id' => 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 'group_id' => $group->id,
        'provider' => 'incus', 'name' => 'ot-0a68f778a3', 'state' => 'running', 'desired_power' => 'running',
        'spec' => ['host_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'images' => $images, 'subnet' => '10.233.7.0/24',
            'source_template' => ['id' => '9862e1aa-605c-4b49-a65b-6cf0b3a96dfe', 'repository' => 'https://github.com/acme/orbit.git', 'base' => 'main', 'commit' => str_repeat('c', 40)]]]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $host->id, 'name' => 'task-'.$group->id,
        'checkout_path' => '/home/orbit/orbit', 'status' => 'source_resolved', 'task_sandbox_id' => $sandbox->id]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);
    config(['compute.incus.hosts' => [['node_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'pool' => 'proof', 'max_vms' => 2,
        'orbit_images' => $images, 'project_images' => [], 'blocked_networks' => ['192.168.0.0/16']]]]);
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    $state = (object) ['calls' => [], 'fail' => null, 'endpoint' => '10.233.7.11:51820', 'changedHead' => false];
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function ($connection, RemoteCommand $command) use ($state, $sandbox): CommandResult {
        expect($command->arguments)->toBe(['/usr/local/bin/orbit-agent', 'sandbox']);
        $envelope = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        $guest = $envelope['guest'];
        $phase = match ($guest['argv'][0]) {
            'python3' => json_decode(base64_decode($guest['stdin']), true, flags: JSON_THROW_ON_ERROR)['phase'],
            'php' => 'identity',
            'sudo' => 'vpn',
        };
        $state->calls[] = $phase.':'.$guest['role'];
        if ($phase === 'identity') {
            expect($guest['argv'])->toBe(['php', '/dev/stdin', '/home/orbit/.orbit/gateway.sqlite', '10.233.7.11', '10.233.7.10']);
        }
        if ($phase === 'vpn') {
            expect($guest['argv'])->toBe(['sudo', '-n', 'bash', '-seu', '--', '10.233.7.11', '10.233.7.11:51820']);
        }
        $body = $phase === 'identity' ? ['operator' => $state->endpoint] : ['sandbox_id' => $sandbox->id, 'head' => str_repeat($state->changedHead && $phase === 'gateway' ? 'b' : 'a', 40), 'ready' => true];

        return new CommandResult(0, json_encode(['name' => $sandbox->name, 'role' => $guest['role'], 'exit_code' => $state->fail === $phase ? 1 : 0,
            'stdout' => base64_encode(json_encode($body)), 'stderr' => base64_encode($state->fail === $phase ? 'private-guest-key' : ''),
            'duration_ms' => 1, 'truncated' => false, 'timed_out' => false]), '', 1, false);
    });

    return [$workspace, $state];
}

it('prepares only the recorded pair and verifies its branch through the operator CLI', function (): void {
    [$workspace, $state] = runtime_pair();

    app(SandboxPairRuntime::class)->prepare($workspace);

    expect($state->calls)->toBe(['inspect:operator', 'identity:gateway', 'vpn:operator', 'gateway:gateway', 'operator:operator']);
});

it('stops pair preparation at a failed phase without retaining private guest output', function (string $phase): void {
    [$workspace, $state] = runtime_pair();
    $state->fail = $phase;
    try {
        app(SandboxPairRuntime::class)->prepare($workspace);
        $this->fail('The failed pair phase must refuse readiness.');
    } catch (ComputeException $exception) {
        expect($exception->getMessage())->not->toContain('private-guest-key')->and($exception->getPrevious())->toBeNull();
    }
    expect(explode(':', $state->calls[array_key_last($state->calls)])[0])->toBe($phase);
})->with(['inspect', 'identity', 'vpn', 'gateway', 'operator']);

it('refuses a foreign endpoint before changing operator WireGuard', function (): void {
    [$workspace, $state] = runtime_pair();
    $state->endpoint = '10.44.0.2:51820';

    expect(fn () => app(SandboxPairRuntime::class)->prepare($workspace))->toThrow(ComputeException::class);
    expect($state->calls)->toBe(['inspect:operator', 'identity:gateway']);
});

it('refuses a branch change before reporting CLI readiness', function (): void {
    [$workspace, $state] = runtime_pair();
    $state->changedHead = true;

    expect(fn () => app(SandboxPairRuntime::class)->prepare($workspace))->toThrow(ComputeException::class);
    expect($state->calls)->not->toContain('operator:operator');
});

it('refuses unprepared source without contacting either guest', function (): void {
    [$workspace, $state] = runtime_pair();
    $workspace->update(['status' => 'reserved']);

    expect(fn () => app(SandboxPairRuntime::class)->prepare($workspace))->toThrow(ComputeException::class);
    expect($state->calls)->toBe([]);
});

it('checks guest runtime ownership dependencies and isolated CLI identity', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/Compute/guest_pair_runtime_test.py'), resource_path('compute/guest-pair-runtime.py')]);
    $process->mustRun();
    expect($process->getExitCode())->toBe(0);
});

it('refuses malformed pair images without contacting either guest', function (mixed $images): void {
    [$workspace, $state] = runtime_pair();
    $sandbox = $workspace->taskSandbox;
    $sandbox->update(['spec' => [...$sandbox->spec, 'images' => $images]]);

    expect(fn () => app(SandboxPairRuntime::class)->prepare($workspace))->toThrow(ComputeException::class);
    expect($state->calls)->toBe([]);
})->with(['missing images' => [null], 'scalar images' => ['invalid'], 'missing gateway' => [['operator' => str_repeat('a', 64)]]]);
