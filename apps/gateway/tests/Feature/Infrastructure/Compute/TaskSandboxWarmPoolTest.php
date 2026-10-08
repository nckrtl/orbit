<?php

declare(strict_types=1);

use App\Actions\Compute\AllocateTaskSandboxAction;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskScheduler;
use App\Infrastructure\Compute\TaskSandboxWarmPool;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Str;
use Tests\Support\FakeSandboxModelProxy;

use function Pest\Laravel\mock;

function warm_fixture(int $available = 2): array
{
    (new FakeSandboxModelProxy)->install();
    app(TaskExtensionState::class)->enable();
    $host = Node::query()->create(['name' => 'warm-host', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'user' => 'orbit']);
    $settings = ['node_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'pool' => 'proof', 'max_vms' => 2, 'warm_pairs' => 1,
        'orbit_images' => ['operator' => str_repeat('a', 64), 'gateway' => str_repeat('b', 64)], 'project_images' => [],
        'orbit_source_template' => ['id' => 'e2d3499f-37bc-4e2e-91c0-1d6fd13a11a2', 'repository' => 'https://github.com/acme/orbit.git', 'base' => 'main', 'commit' => str_repeat('c', 40)],
        'blocked_networks' => ['192.168.0.0/16'], 'gateway_address' => '10.44.0.2', 'model_proxy_origin' => 'http://127.0.0.1:28317'];
    config(['compute.incus.enabled' => true, 'compute.incus.hosts' => [$settings], 'compute.orbit_claims_enabled' => true]);
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    $transport = new class($available) implements SshExecutor
    {
        public array $requests = [];

        public bool $fail = false;

        public function __construct(public int $available) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            $this->requests[] = $request;
            if ($request['operation'] === 'capacity') {
                return new CommandResult(0, json_encode(['available' => $this->available, 'used' => $request['budget'] - $this->available, 'budget' => $request['budget']], JSON_THROW_ON_ERROR), '', 1, false);
            }
            if ($this->fail) {
                return new CommandResult(1, '', 'private-host-output', 1, false);
            }
            $row = TaskSandbox::query()->findOrFail($request['sandbox_id']);
            $destroyed = $request['operation'] === 'destroy';

            return new CommandResult(0, json_encode(['name' => $row->name, 'power' => $destroyed ? 'destroyed' : 'running', 'instances' => $destroyed ? [] : array_map(
                fn (string $role): array => ['name' => $row->name.'-'.$role, 'state' => 'running'], array_keys($row->spec['images']),
            )], JSON_THROW_ON_ERROR), '', 1, false);
        }
    };
    app()->instance(SshExecutor::class, $transport);

    return [$host, $settings, $transport];
}

function warm_group(): Task
{
    $project = Project::query()->firstOrCreate(['slug' => 'orbit'], ['name' => 'Orbit', 'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main']);

    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Warm claim', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Work', 'brief' => 'Work', 'status' => 'todo']);

    return $group;
}

function warm_slot(Node $host, array $settings): TaskSandbox
{
    $id = (string) Str::uuid();

    return TaskSandbox::query()->create(['id' => $id, 'name' => 'ot-'.substr(hash('sha256', $id), 0, 10), 'provider' => 'incus', 'warm_pool' => true, 'state' => SandboxState::Running, 'desired_power' => 'running',
        'spec' => ['host_id' => $host->id, 'project' => $settings['project'], 'pool' => $settings['pool'], 'images' => $settings['orbit_images'], 'source_template' => $settings['orbit_source_template'],
            'subnet' => '10.233.1.0/24', 'blocked_networks' => $settings['blocked_networks'], 'pi_host' => $host->wireguard_ip, 'pi_port' => 23001,
            'gateway_address' => $settings['gateway_address'], 'model_proxy_origin' => $settings['model_proxy_origin']]]);
}

it('assigns the existing warm identity at a full budget and never returns it to the pool', function (): void {
    [$host, $settings] = warm_fixture(0);
    $slot = warm_slot($host, $settings);
    $spec = $slot->spec;
    $group = warm_group();

    $claimed = app(AllocateTaskSandboxAction::class)->execute($group);

    expect($claimed->id)->toBe($slot->id)->and($claimed->spec)->toBe($spec)->and($claimed->group_id)->toBe($group->id)->and($claimed->warm_pool)->toBeFalse();
    expect($claimed->model_key)->not->toBeNull()->and($claimed->pi_token)->not->toBeNull();
    $group->delete();
    expect($claimed->fresh()->group_id)->toBeNull()->and($claimed->fresh()->warm_pool)->toBeFalse();
    expect(fn () => app(AllocateTaskSandboxAction::class)->execute(warm_group()))->toThrow(ComputeException::class);
});

it('prepares one credential-free pair and keeps the same reservation through a failed retry', function (): void {
    [$host, $settings, $transport] = warm_fixture();
    $transport->fail = true;
    $pool = app(TaskSandboxWarmPool::class);
    $pool->reconcile();
    $slot = TaskSandbox::query()->sole();
    expect($slot->warm_pool)->toBeTrue()->and($slot->group_id)->toBeNull()->and($slot->model_key)->toBeNull()->and($slot->pi_token)->toBeNull();
    $transport->fail = false;
    $pool->reconcile();

    expect($slot->fresh()->state)->toBe(SandboxState::Running)->and(TaskSandbox::query()->count())->toBe(1);
    expect(array_unique(array_column(array_filter($transport->requests, fn (array $r): bool => $r['operation'] === 'provision'), 'sandbox_id')))->toBe([$slot->id]);
    expect($slot->fresh()->model_key)->toBeNull()->and($slot->fresh()->pi_token)->toBeNull();
});

it('leaves a foreign source template unassigned and waits for capacity', function (): void {
    [$host, $settings] = warm_fixture(0);
    $slot = warm_slot($host, $settings);
    $spec = $slot->spec;
    $spec['source_template']['commit'] = str_repeat('d', 40);
    $slot->update(['spec' => $spec]);

    expect(fn () => app(AllocateTaskSandboxAction::class)->execute(warm_group()))->toThrow(ComputeException::class);
    expect($slot->fresh()->group_id)->toBeNull()->and($slot->fresh()->warm_pool)->toBeTrue();
});

it('gives a waiting Orbit claim priority over replenishment', function (): void {
    [, , $transport] = warm_fixture();
    warm_group();

    app(TaskSandboxWarmPool::class)->reconcile();

    expect(TaskSandbox::query()->count())->toBe(0)->and($transport->requests)->toBe([]);
});

it('drains an unassigned pair after its pool target becomes zero', function (): void {
    [$host, $settings, $transport] = warm_fixture(0);
    $slot = warm_slot($host, $settings);
    $settings['warm_pairs'] = 0;
    config(['compute.incus.hosts' => [$settings]]);

    app(TaskSandboxWarmPool::class)->reconcile();

    expect($slot->fresh()->state)->toBe(SandboxState::Destroyed)->and($slot->fresh()->group_id)->toBeNull();
    expect(array_column($transport->requests, 'operation'))->toBe(['destroy']);
});

it('keeps a warm reservation out of the orphan sweep', function (): void {
    [$host, $settings, $transport] = warm_fixture(0);
    $slot = warm_slot($host, $settings);

    app(TaskScheduler::class)->removeAbandonedWorkspaces();

    expect($slot->fresh()->state)->toBe(SandboxState::Running)->and($transport->requests)->toBe([]);
});

it('rejects a claim for a different repository before assigning its warm slot', function (): void {
    [$host, $settings] = warm_fixture(0);
    $slot = warm_slot($host, $settings);
    $group = warm_group();
    $group->project->update(['repository_url' => 'https://github.com/acme/other.git']);

    expect(fn () => app(AllocateTaskSandboxAction::class)->execute($group))->toThrow(ComputeException::class);
    expect($slot->fresh()->group_id)->toBeNull()->and($slot->fresh()->model_key)->toBeNull();
});

it('refuses a marked pair that already holds a credential', function (): void {
    [$host, $settings, $transport] = warm_fixture(0);
    $slot = warm_slot($host, $settings);
    $slot->pi_token = 'existing-pi-token';
    $slot->save();
    $settings['warm_pairs'] = 0;
    config(['compute.incus.hosts' => [$settings]]);

    app(TaskSandboxWarmPool::class)->reconcile();

    expect($slot->fresh()->state)->toBe(SandboxState::Running)->and($transport->requests)->toBe([]);
});

it('counts warm reservations against cold claims and never assigns one twice', function (): void {
    [$host, $settings] = warm_fixture(2);
    $slot = warm_slot($host, $settings);
    $first = app(AllocateTaskSandboxAction::class)->execute(warm_group());

    expect($first->id)->toBe($slot->id);
    expect(fn () => app(AllocateTaskSandboxAction::class)->execute(warm_group()))->toThrow(ComputeException::class);
    expect(TaskSandbox::query()->count())->toBe(1)->and($slot->fresh()->group_id)->toBe($first->group_id);
});

it('keeps the default pool disabled without observing or changing host resources', function (): void {
    [, $settings, $transport] = warm_fixture();
    unset($settings['warm_pairs']);
    config(['compute.incus.hosts' => [$settings]]);

    app(TaskSandboxWarmPool::class)->reconcile();

    expect(TaskSandbox::query()->count())->toBe(0)->and($transport->requests)->toBe([]);
});

it('replenishes after the scheduler has attempted its claims', function (): void {
    [, , $transport] = warm_fixture();

    expect(app(TaskScheduler::class)->claimAvailable())->toBe(0);
    expect(TaskSandbox::query()->sole()->warm_pool)->toBeTrue();
    expect(array_column($transport->requests, 'operation'))->toBe(['capacity', 'provision']);
});

it('rejects an invalid or over-budget warm target before observing the host', function (mixed $target): void {
    [, $settings, $transport] = warm_fixture();
    $settings['warm_pairs'] = $target;
    config(['compute.incus.hosts' => [$settings]]);

    expect(fn () => app(TaskSandboxWarmPool::class)->reconcile())->toThrow(ComputeException::class);
    expect($transport->requests)->toBe([]);
})->with([-1, 3, 1.0, '1', 2]);

it('cleans a stale image slot through its original identity before replenishment', function (): void {
    [$host, $settings, $transport] = warm_fixture(0);
    $slot = warm_slot($host, $settings);
    $settings['orbit_images']['operator'] = str_repeat('d', 64);
    config(['compute.incus.hosts' => [$settings]]);

    app(TaskSandboxWarmPool::class)->reconcile();

    expect($slot->fresh()->state)->toBe(SandboxState::Destroyed);
    expect(array_column($transport->requests, 'sandbox_id'))->toBe([$slot->id]);
    expect(array_column($transport->requests, 'operation'))->toBe(['destroy']);
});

it('preserves an unconfigured host reservation for operator recovery', function (): void {
    [$host, $settings, $transport] = warm_fixture(0);
    $slot = warm_slot($host, $settings);
    config(['compute.incus.hosts' => []]);

    app(TaskSandboxWarmPool::class)->reconcile();

    expect($slot->fresh()->state)->toBe(SandboxState::Running)->and($transport->requests)->toBe([]);
});
