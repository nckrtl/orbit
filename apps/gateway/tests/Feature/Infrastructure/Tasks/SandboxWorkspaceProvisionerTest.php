<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Tasks\InstanceProvisionFailure;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCompute;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\TaskWorkspaceProvisioner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Tests\Support\FakeSandboxModelProxy;

use function Pest\Laravel\mock;

/** @return array{Task, object{calls: list<string>, fail: string|null, available: int}} */
function sandbox_claim(): array
{
    (new FakeSandboxModelProxy)->install();
    $host = Node::query()->create(['name' => 'compute', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'user' => 'orbit']);
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Claim', 'brief' => 'Work', 'status' => 'reserved', 'reserved_at' => now(),
        'task_compute' => TaskCompute::Vm, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    config(['compute.orbit_claims_enabled' => true, 'compute.incus.enabled' => true,
        'compute.pi.models' => [['id' => 'gpt-5.4', 'name' => 'GPT', 'reasoning' => true, 'input' => ['text'], 'contextWindow' => 128000, 'maxTokens' => 16384]],
        'compute.incus.hosts' => [['node_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'pool' => 'proof', 'max_vms' => 2,
            'orbit_images' => ['operator' => str_repeat('a', 64), 'gateway' => str_repeat('b', 64)], 'project_images' => [],
            'orbit_source_template' => ['id' => '9862e1aa-605c-4b49-a65b-6cf0b3a96dfe', 'repository' => 'https://github.com/acme/orbit.git', 'base' => 'main', 'commit' => str_repeat('c', 40)],
            'blocked_networks' => ['192.168.0.0/16'], 'gateway_address' => '10.44.0.2', 'model_proxy_origin' => 'http://127.0.0.1:28317']]]);
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    $state = (object) ['calls' => [], 'fail' => null, 'available' => 2];
    mock(TaskBaseBranchFetcher::class)->shouldReceive('fetchForTurn')->andReturnUsing(function () use ($state): void {
        $state->calls[] = 'fetch';
    });
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function ($connection, RemoteCommand $command) use ($state, $group): CommandResult {
        expect($command->arguments)->toBe(['/usr/local/bin/orbit-agent', 'sandbox']);
        $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        $name = 'ot-'.substr(hash('sha256', $request['sandbox_id']), 0, 10);
        if ($request['operation'] === 'capacity') {
            $state->calls[] = 'capacity';

            return new CommandResult(0, json_encode(['available' => $state->available, 'used' => 2 - $state->available, 'budget' => 2]), '', 1, false);
        }
        if ($request['operation'] === 'provision') {
            $state->calls[] = 'provision';

            return new CommandResult(0, json_encode(['name' => $name, 'power' => 'running', 'instances' => [
                ['name' => $name.'-operator', 'state' => 'running'], ['name' => $name.'-gateway', 'state' => 'running'],
            ]]), '', 1, false);
        }
        expect($group->fresh()->taskable->task_sandbox_id)->toBe($request['sandbox_id']);
        $guest = $request['guest'];
        $input = base64_decode($guest['stdin']);
        $data = json_decode($input, true);
        $phase = match (true) {
            $guest['argv'][0] === 'php' => 'identity',
            $guest['argv'][0] === 'sudo' && $guest['argv'][2] === 'bash' => 'vpn',
            isset($data['pi_token']) => 'pi',
            isset($data['address'], $data['gateway']) => 'pi-network',
            isset($data['phase']) => 'pair-'.$data['phase'],
            default => $data['operation'],
        };
        $state->calls[] = $phase;
        $body = match ($phase) {
            'initialize' => ['initialized' => true],
            'identity' => ['operator' => '10.233.1.11:51820'],
            default => ['sandbox_id' => $request['sandbox_id'], 'head' => str_repeat('d', 40), 'starting_commit' => str_repeat('d', 40), 'ready' => true],
        };

        return new CommandResult(0, json_encode(['name' => $name, 'role' => $guest['role'], 'exit_code' => $state->fail === $phase ? 1 : 0,
            'stdout' => base64_encode(json_encode($body)), 'stderr' => base64_encode($state->fail === $phase ? 'private-key-material' : ''),
            'duration_ms' => 1, 'truncated' => false, 'timed_out' => false]), '', 1, false);
    });

    return [$group->fresh(), $state];
}

it('attaches the owned workspace before guest preparation and returns only after Pi is ready', function (): void {
    [$group, $state] = sandbox_claim();

    $workspace = app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($group));

    expect($workspace)->toBeInstanceOf(Instance::class);
    expect($workspace->status)->toBe(InstanceState::SourceResolved)->and($workspace->task_workspace_routed)->toBeFalse();
    expect($group->fresh()->taskable_id)->toBe($workspace->id);
    expect($state->calls)->toBe(['capacity', 'provision', 'initialize', 'github_dns', 'fetch', 'checkout', 'pair-inspect', 'identity', 'vpn', 'pair-gateway', 'pair-prerequisites', 'pair-operator', 'pi-network', 'pi']);
    expect($workspace->taskSandbox->model_key_registered_at)->not->toBeNull();
});

it('keeps failed preparation attached and retries the same source without a second fetch', function (): void {
    [$group, $state] = sandbox_claim();
    $state->fail = 'pair-gateway';
    $provisioner = app(TaskWorkspaceProvisioner::class);

    expect(fn () => $provisioner->provision(InstanceProvisionIntent::for($group)))->toThrow(TaskCapacityException::class, 'Gateway branch runtime');
    $workspace = $group->fresh()->taskable;
    expect($workspace->status)->toBe(InstanceState::SourceResolved);
    expect($state->calls)->not->toContain('pi');
    $state->fail = null;
    $state->calls = [];
    $retried = $provisioner->provision(InstanceProvisionIntent::for($group->fresh()));

    expect($retried->id)->toBe($workspace->id)->and(Instance::query()->count())->toBe(1)->and(TaskSandbox::query()->count())->toBe(1);
    expect($state->calls)->not->toContain('initialize', 'github_dns', 'fetch', 'checkout')->toContain('inspect', 'pi');
});

it('never returns a claim after any failed guest step and redacts guest output', function (string $phase): void {
    [$group, $state] = sandbox_claim();
    $state->fail = $phase;
    try {
        $result = app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($group));
        expect($result)->toBeInstanceOf(InstanceProvisionFailure::class);
        expect($result->cause)->not->toContain('private-key-material');
    } catch (TaskCapacityException $exception) {
        expect($exception->getMessage())->not->toContain('private-key-material')->and($exception->getPrevious())->toBeNull();
    }
    expect($state->calls[array_key_last($state->calls)])->toBe($phase);
    expect($group->fresh()->taskable_id)->not->toBeNull();
})->with(['initialize', 'checkout', 'pair-inspect', 'identity', 'vpn', 'pair-gateway', 'pair-prerequisites', 'pair-operator', 'pi-network', 'pi']);

it('waits without allocating for disabled or incomplete rollout prerequisites', function (string $key, mixed $value): void {
    [$group, $state] = sandbox_claim();
    config([$key => $value]);

    expect(fn () => app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($group)))->toThrow(TaskCapacityException::class);
    expect($state->calls)->toBe([])->and(TaskSandbox::query()->count())->toBe(0)->and(Instance::query()->count())->toBe(0);
})->with([
    ['compute.orbit_claims_enabled', false], ['compute.incus.enabled', false], ['compute.model_proxy.enabled', false],
    ['compute.pi.models', []], ['compute.incus.hosts.0.orbit_source_template', null], ['compute.incus.hosts.0.gateway_address', null],
    ['compute.incus.hosts.0.model_proxy_origin', null], ['compute.incus.hosts.0.orbit_images', []],
]);

it('does not fall back when the local VM budget is full', function (): void {
    [$group, $state] = sandbox_claim();
    $state->available = 0;

    expect(fn () => app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($group)))->toThrow(TaskCapacityException::class, 'capacity');
    expect($state->calls)->toBe(['capacity'])->and(TaskSandbox::query()->count())->toBe(0);
});

it('refuses stale cancelled held and project lane claims before host contact', function (string $change): void {
    [$group, $state] = sandbox_claim();
    match ($change) {
        'cancelled' => Task::topLevel()->whereKey($group->id)->update(['status' => 'cancelled']),
        'held' => Task::topLevel()->whereKey($group->id)->update(['watched_pr_completion' => 'merged']),
        'stale' => Task::topLevel()->whereKey($group->id)->update(['reserved_at' => now()->addMinute()]),
        'project' => $group->project->update(['slug' => 'dlf']),
        'driver' => $group->update(['reviewer_agent_driver' => 't3']),
    };

    expect(fn () => app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($group)))->toThrow(TaskCapacityException::class);
    expect($state->calls)->toBe([]);
})->with(['cancelled', 'held', 'stale', 'project', 'driver']);

it('never adopts an unrelated same name workspace', function (): void {
    [$group, $state] = sandbox_claim();
    $existing = Instance::query()->create(['project_id' => $group->project_id, 'node_id' => config('compute.incus.hosts.0.node_id'), 'name' => 'task-'.$group->id, 'checkout_path' => '/live/work']);

    expect(fn () => app(TaskWorkspaceProvisioner::class)->provision(InstanceProvisionIntent::for($group)))->toThrow(TaskCapacityException::class, 'already occupied');
    expect($state->calls)->toBe([])->and($existing->fresh()->checkout_path)->toBe('/live/work')->and($group->fresh()->taskable_id)->toBeNull();
});
