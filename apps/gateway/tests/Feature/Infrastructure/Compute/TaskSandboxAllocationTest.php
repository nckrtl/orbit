<?php

declare(strict_types=1);

use App\Actions\Compute\AllocateTaskSandboxAction;
use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCompute;
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
use Tests\Support\FakeSandboxModelProxy;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    (new FakeSandboxModelProxy)->install();
    $this->host = Node::query()->create(['name' => 'compute', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'user' => 'orbit']);
    $this->settings = ['node_id' => $this->host->id, 'project' => 'orbit-task-sandboxes', 'pool' => 'proof', 'max_vms' => 2,
        'orbit_images' => ['operator' => str_repeat('a', 64), 'gateway' => str_repeat('b', 64)],
        'project_images' => ['dlf' => str_repeat('c', 64)], 'blocked_networks' => ['192.168.0.0/16']];
    config(['compute.incus.enabled' => true, 'compute.incus.hosts' => [$this->settings], 'compute.upcloud.enabled' => true,
        'compute.upcloud.zone' => 'nl-ams1', 'compute.upcloud.gateway_address' => '93.184.216.34',
        'compute.upcloud.wireguard_address' => '93.184.216.35', 'compute.upcloud.wireguard_port' => 51820]);
    $keys = mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakePublicMaterial');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
});

function allocation_group(string $slug): Task
{
    $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'repository_url' => 'https://github.com/acme/'.$slug.'.git']);

    return Task::topLevel()->create(['project_id' => $project->id, 'title' => 'VM work', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
}

function allocation_host(int $available): void
{
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function (SshConnection $connection, RemoteCommand $command) use ($available): CommandResult {
        $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        if ($request['operation'] === 'capacity') {
            return new CommandResult(0, json_encode(['available' => $available, 'used' => 2 - $available, 'budget' => 2], JSON_THROW_ON_ERROR), '', 1, false);
        }
        $name = 'ot-'.substr(hash('sha256', $request['sandbox_id']), 0, 10);
        $row = TaskSandbox::query()->findOrFail($request['sandbox_id']);
        expect($row->state)->toBe($request['operation'] === 'resume' ? SandboxState::Starting : SandboxState::Creating);
        $images = $row->spec['images'];
        if ($request['operation'] === 'provision') {
            expect($request['spec']['source_template'] ?? null)->toBe($row->spec['source_template'] ?? null);
        }

        return new CommandResult(0, json_encode(['name' => $name, 'power' => 'running', 'instances' => array_map(
            fn (string $role): array => ['name' => $name.'-'.$role, 'state' => 'running'], array_keys($images),
        )], JSON_THROW_ON_ERROR), '', 1, false);
    });
}

describe('sandbox placement', function (): void {
    it('places the Orbit pair locally and preserves placement through retry and resume', function (): void {
        mock(ComputeDriver::class)->shouldNotReceive('capacity', 'provision');
        allocation_host(2);
        $group = allocation_group('orbit');
        $allocator = app(AllocateTaskSandboxAction::class);
        $sandbox = $allocator->execute($group);
        expect($sandbox->provider)->toBe('incus')->and($sandbox->state)->toBe(SandboxState::Running)
            ->and(array_keys($sandbox->spec['images']))->toBe(['operator', 'gateway'])
            ->and($sandbox->model_key_registered_at)->not->toBeNull()->and($sandbox->pi_token)->not->toBeNull();
        expect($allocator->execute($group)->id)->toBe($sandbox->id);
        $sandbox->update(['state' => SandboxState::Stopped, 'desired_power' => 'stopped']);
        expect($allocator->execute($group)->state)->toBe(SandboxState::Running)
            ->and(TaskSandbox::query()->count())->toBe(1);
    });

    it('keeps the private Pi port reserved while parked and assigns another port to the next group', function (): void {
        $this->settings['gateway_address'] = '10.44.0.2';
        config(['compute.incus.hosts' => [$this->settings]]);
        allocation_host(2);
        $group = allocation_group('orbit');
        $first = app(AllocateTaskSandboxAction::class)->execute($group);
        expect($first->spec['pi_host'])->toBe('10.44.0.20')->and($first->spec['gateway_address'])->toBe('10.44.0.2')
            ->and($first->spec['pi_port'])->toBe(23001);
        $first->update(['state' => SandboxState::Stopped, 'desired_power' => 'stopped']);
        $next = Task::topLevel()->create(['project_id' => $group->project_id, 'title' => 'Next', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
        $second = app(AllocateTaskSandboxAction::class)->execute($next);
        expect($second->spec['pi_port'])->toBe(23002)->and($first->fresh()->spec['pi_port'])->toBe(23001);
    });

    it('rejects a public Pi control address before contacting a compute host', function (): void {
        $this->settings['gateway_address'] = '203.0.113.5';
        config(['compute.incus.hosts' => [$this->settings]]);
        mock(SshExecutor::class)->shouldReceive('execute')->never();

        expect(fn () => app(AllocateTaskSandboxAction::class)->execute(allocation_group('orbit')))
            ->toThrow(ComputeException::class, 'configuration is invalid');
        expect(TaskSandbox::query()->count())->toBe(0);
    });

    it('sends project work to cloud only when local capacity is full', function (): void {
        allocation_host(0);
        $cloud = mock(ComputeDriver::class);
        $cloud->shouldReceive('capacity')->once()->andReturn(1);
        $cloud->shouldReceive('provision')->once()->andReturnUsing(fn (TaskSandbox $sandbox): TaskSandbox => $sandbox);
        $sandbox = app(AllocateTaskSandboxAction::class)->execute(allocation_group('dlf'));
        expect($sandbox->provider)->toBe('upcloud')->and(TaskSandbox::query()->count())->toBe(1);
    });

    it('never sends the Orbit lane or an unobservable host to cloud', function (): void {
        allocation_host(0);
        mock(ComputeDriver::class)->shouldNotReceive('capacity', 'provision');
        expect(fn () => app(AllocateTaskSandboxAction::class)->execute(allocation_group('orbit')))
            ->toThrow(ComputeException::class, 'local Incus');
        mock(SshExecutor::class)->shouldReceive('execute')->andReturn(new CommandResult(255, '', 'unreachable', 1, false));
        expect(fn () => app(AllocateTaskSandboxAction::class)->execute(allocation_group('dlf')))
            ->toThrow(ResourceOperationException::class);
        expect(TaskSandbox::query()->count())->toBe(0);
    });

    it('reserves capacity before guests exist and refuses shared-mode groups', function (): void {
        allocation_host(2);
        mock(ComputeDriver::class)->shouldNotReceive('capacity', 'provision');
        $first = allocation_group('orbit');
        $sandbox = app(AllocateTaskSandboxAction::class)->execute($first);
        $sandbox->update(['state' => SandboxState::Reserved]);
        $second = Task::topLevel()->create(['project_id' => $first->project_id, 'title' => 'Next', 'brief' => 'Wait', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
        expect(fn () => app(AllocateTaskSandboxAction::class)->execute($second))->toThrow(ComputeException::class, 'capacity');
        $shared = Task::topLevel()->create(['project_id' => $first->project_id, 'title' => 'Shared', 'brief' => 'Keep', 'status' => 'todo', 'task_compute' => TaskCompute::Shared]);
        expect(fn () => app(AllocateTaskSandboxAction::class)->execute($shared))->toThrow(ComputeException::class, 'pinned to VM');
        expect(TaskSandbox::query()->count())->toBe(1);
    });
});

it('freezes the host model relay endpoint only after its group key is registered there', function (): void {
    $this->settings['model_proxy_origin'] = 'http://127.0.0.1:28317';
    config(['compute.incus.hosts' => [$this->settings]]);
    allocation_host(2);
    $sandbox = app(AllocateTaskSandboxAction::class)->execute(allocation_group('orbit'));

    expect($sandbox->spec['model_proxy_origin'])->toBe('http://127.0.0.1:28317')
        ->and($sandbox->model_proxy_origin)->toBe('http://127.0.0.1:28317')->and($sandbox->model_key_registered_at)->not->toBeNull();
});

it('refuses unsafe model relay origins before contacting a compute host', function (string $origin): void {
    $this->settings['model_proxy_origin'] = $origin;
    config(['compute.incus.hosts' => [$this->settings]]);
    mock(SshExecutor::class)->shouldReceive('execute')->never();

    expect(fn () => app(AllocateTaskSandboxAction::class)->execute(allocation_group('orbit')))->toThrow(ComputeException::class, 'configuration is invalid');
    expect(TaskSandbox::query()->count())->toBe(0);
})->with(['http://8.8.8.8:8317', 'http://10.44.0.3/v0/management', 'http://secret@10.44.0.3', 'http://10.44.0.3?key=secret']);

it('pins the source template through allocation and configuration changes', function (): void {
    $template = ['id' => '9862e1aa-605c-4b49-a65b-6cf0b3a96dfe', 'repository' => 'https://github.com/acme/orbit.git', 'base' => 'main', 'commit' => str_repeat('c', 40)];
    $this->settings['orbit_source_template'] = $template;
    config(['compute.incus.hosts' => [$this->settings]]);
    allocation_host(2);
    $group = allocation_group('orbit');
    $group->project->update(['default_branch' => 'main']);
    $allocator = app(AllocateTaskSandboxAction::class);

    $sandbox = $allocator->execute($group);
    $this->settings['orbit_source_template']['commit'] = str_repeat('d', 40);
    config(['compute.incus.hosts' => [$this->settings]]);
    $again = $allocator->execute($group);

    expect($sandbox->spec['source_template'])->toBe($template)
        ->and($again->id)->toBe($sandbox->id)->and($again->spec['source_template'])->toBe($template);
});

it('refuses invalid or mismatched source templates before host contact', function (string $field, mixed $value): void {
    $this->settings['orbit_source_template'] = ['id' => '9862e1aa-605c-4b49-a65b-6cf0b3a96dfe', 'repository' => 'https://github.com/acme/orbit.git', 'base' => 'main', 'commit' => str_repeat('c', 40)];
    $this->settings['orbit_source_template'][$field] = $value;
    config(['compute.incus.hosts' => [$this->settings]]);
    mock(SshExecutor::class)->shouldReceive('execute')->never();
    $group = allocation_group('orbit');
    $group->project->update(['default_branch' => 'main']);

    expect(fn () => app(AllocateTaskSandboxAction::class)->execute($group))->toThrow(ComputeException::class);
    expect(TaskSandbox::query()->count())->toBe(0);
})->with([
    ['id', 'invalid'], ['repository', 'https://github.com/acme/foreign.git'],
    ['base', 'other'], ['base', '../unsafe'], ['commit', 'not-a-sha'], ['command', 'unexpected'],
]);
