<?php

declare(strict_types=1);

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Infrastructure\Compute\ComputeLocks;
use App\Infrastructure\Compute\IncusComputeDriver;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Models\Node;
use App\Models\TaskSandbox;

use function Pest\Laravel\mock;

beforeEach(function (): void {
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    $this->host = Node::query()->create([
        'name' => 'compute', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'user' => 'orbit',
    ]);
    $this->sandbox = TaskSandbox::query()->create([
        'id' => 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 'provider' => 'incus', 'name' => 'ot-0a68f778a3',
        'state' => SandboxState::Reserved, 'desired_power' => 'running',
        'spec' => ['host_id' => $this->host->id, 'project' => 'orbit-task-sandboxes',
            'images' => ['operator' => str_repeat('a', 64), 'gateway' => str_repeat('b', 64)],
            'pool' => 'proof', 'subnet' => '10.233.201.0/24', 'blocked_networks' => ['192.168.0.0/16']],
    ]);
});

function incus_test_driver(Node $host): IncusComputeDriver
{
    return new IncusComputeDriver($host, 'orbit-task-sandboxes', 4, app(IncusSandboxHost::class), app(ComputeLocks::class));
}

function incus_host_result(string $power, array $roles = ['operator', 'gateway']): CommandResult
{
    return new CommandResult(0, json_encode([
        'name' => 'ot-0a68f778a3', 'power' => $power,
        'instances' => array_map(fn (string $role): array => ['name' => 'ot-0a68f778a3-'.$role, 'state' => $power], $roles),
    ], JSON_THROW_ON_ERROR), '', 1, false);
}

describe('local Incus driver', function (): void {
    it('records intent before host IO and parks resumes and destroys the same identity', function (): void {
        $expected = ['provision', 'park', 'resume', 'destroy'];
        mock(SshExecutor::class)->shouldReceive('execute')->times(4)->andReturnUsing(function (SshConnection $connection, RemoteCommand $command) use (&$expected): CommandResult {
            $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            $operation = array_shift($expected);
            expect($request['operation'])->toBe($operation)
                ->and($request['sandbox_id'])->toBe($this->sandbox->id);
            $sandbox = $this->sandbox->fresh();
            expect($sandbox->state)->toBe(match ($operation) {
                'provision' => SandboxState::Creating, 'park' => SandboxState::Stopping,
                'resume' => SandboxState::Starting, 'destroy' => SandboxState::Destroying,
            });
            if ($operation === 'provision') {
                expect($sandbox->create_attempted_at)->not->toBeNull()
                    ->and($request['spec'])->not->toHaveKeys(['host_id', 'project']);
            }

            return match ($operation) {
                'park' => incus_host_result('stopped'),
                'destroy' => incus_host_result('destroyed', []),
                default => incus_host_result('running'),
            };
        });
        $driver = incus_test_driver($this->host);
        expect($driver->provision($this->sandbox)->state)->toBe(SandboxState::Running)
            ->and($this->sandbox->network_policy)->toBe('sealed');
        expect($driver->park($this->sandbox)->state)->toBe(SandboxState::Stopped);
        expect($driver->resume($this->sandbox)->state)->toBe(SandboxState::Running);
        expect($driver->destroy($this->sandbox)->state)->toBe(SandboxState::Destroyed)
            ->and($this->sandbox->destroyed_at)->not->toBeNull();
        expect($driver->destroy($this->sandbox)->state)->toBe(SandboxState::Destroyed);
        expect(fn () => $driver->resume($this->sandbox))->toThrow(ComputeException::class, 'already been destroyed');
    });

    it('retains uncertain ownership and retries under the same sandbox identity', function (): void {
        mock(SshExecutor::class)->shouldReceive('execute')->twice()
            ->andReturn(new CommandResult(255, '', 'connection lost', 1, false), incus_host_result('running'));
        $driver = incus_test_driver($this->host);
        expect(fn () => $driver->provision($this->sandbox))->toThrow(ComputeException::class, 'unresolved');
        expect($this->sandbox->fresh()->state)->toBe(SandboxState::Uncertain);
        expect($driver->provision($this->sandbox)->state)->toBe(SandboxState::Running)
            ->and(TaskSandbox::query()->count())->toBe(1);
    });

    it('does not report a partial pair as ready or absent VMs as cleaned up', function (): void {
        mock(SshExecutor::class)->shouldReceive('execute')->twice()
            ->andReturn(incus_host_result('running', ['operator']), incus_host_result('destroyed', []));
        $driver = incus_test_driver($this->host);
        expect($driver->provision($this->sandbox)->state)->toBe(SandboxState::Creating)
            ->and($this->sandbox->firewall_configured_at)->toBeNull();
        expect($driver->observe($this->sandbox)->state)->toBe(SandboxState::Uncertain)
            ->and($this->sandbox->destroyed_at)->toBeNull();
    });

    it('refuses foreign placement and enrolled-node deletion before host IO', function (): void {
        mock(SshExecutor::class)->shouldReceive('execute')->never();
        $driver = incus_test_driver($this->host);
        $spec = $this->sandbox->spec;
        $this->sandbox->update(['spec' => [...$spec, 'host_id' => $this->host->id + 1]]);
        expect(fn () => $driver->destroy($this->sandbox))->toThrow(ComputeException::class, 'ownership');
        $this->sandbox->update(['spec' => $spec, 'node_id' => $this->host->id]);
        expect(fn () => $driver->destroy($this->sandbox))->toThrow(ComputeException::class, 'Remove the sandbox Node');
    });
});

it('refuses raw destruction before host IO while a model credential remains registered', function (): void {
    $this->sandbox->model_key = str_repeat('e', 64);
    $this->sandbox->save();
    mock(SshExecutor::class)->shouldReceive('execute')->never();

    expect(fn () => incus_test_driver($this->host)->destroy($this->sandbox))->toThrow(ComputeException::class, 'Revoke the sandbox model key');
    expect($this->sandbox->fresh()->model_key)->toBe(str_repeat('e', 64));
});
