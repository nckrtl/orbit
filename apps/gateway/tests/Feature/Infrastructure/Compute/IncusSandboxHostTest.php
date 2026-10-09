<?php

declare(strict_types=1);

use App\Domain\Compute\SandboxState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\SandboxHostOperation;
use App\Domain\Tasks\TaskCompute;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;

use function Pest\Laravel\mock;

function sandbox_host_node(): Node
{
    return Node::query()->create([
        'name' => 'compute', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'user' => 'orbit',
    ]);
}

beforeEach(function (): void {
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
});

describe('typed Incus host transport', function (): void {
    it('sends a fixed agent command with bounded protected input over pinned SSH', function (): void {
        $node = sandbox_host_node();
        mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(function (SshConnection $connection, RemoteCommand $command): CommandResult {
            expect($connection->host)->toBe('10.44.0.20')->and($connection->user)->toBe('orbit')
                ->and($connection->knownHostsFile)->toBe('/keys/known_hosts')
                ->and($command->arguments)->toBe(['/usr/local/bin/orbit-agent', 'sandbox'])
                ->and($command->input)->toBeNull()->and($command->maxOutputBytes)->toBe(65536);
            $input = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            expect($input)->toBe([
                'operation' => 'capacity', 'project' => 'orbit-task-sandboxes',
                'sandbox_id' => 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 'budget' => 4,
            ]);

            return new CommandResult(0, '{"available":2,"used":2,"budget":4}', '', 1, false);
        });

        expect(app(IncusSandboxHost::class)->execute($node, SandboxHostOperation::Capacity, 'orbit-task-sandboxes', 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 4))
            ->toBe(['available' => 2, 'used' => 2, 'budget' => 4]);
    });

    it('rejects inconsistent or substituted host results', function (string $response): void {
        mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturn(new CommandResult(0, $response, '', 1, false));

        expect(fn () => app(IncusSandboxHost::class)->execute(sandbox_host_node(), SandboxHostOperation::Observe, 'orbit-task-sandboxes', 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 4))
            ->toThrow(ResourceOperationException::class);
    })->with([
        'non-JSON' => 'provider-secret',
        'foreign sandbox' => '{"name":"ot-foreign","power":"destroyed","instances":[]}',
        'missing guests' => '{"name":"ot-0a68f778a3","power":"running","instances":[]}',
        'foreign guest' => '{"name":"ot-0a68f778a3","power":"running","instances":[{"name":"ot-foreign-operator","state":"running"}]}',
    ]);

    it('does not expose guest or provider output when the host refuses', function (): void {
        mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturn(new CommandResult(1, 'stdout-secret', 'stderr-secret', 1, false));

        try {
            app(IncusSandboxHost::class)->execute(sandbox_host_node(), SandboxHostOperation::Observe, 'orbit-task-sandboxes', 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 4);
            test()->fail('The failed operation must be refused.');
        } catch (ResourceOperationException $exception) {
            expect($exception->getMessage())->not->toContain('stdout-secret', 'stderr-secret');
        }
    });

    it('rejects invalid identity and provision payloads before connecting', function (): void {
        mock(SshExecutor::class)->shouldReceive('execute')->never();
        $node = sandbox_host_node();

        expect(fn () => app(IncusSandboxHost::class)->execute($node, SandboxHostOperation::Observe, 'default', 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 4))
            ->toThrow(ResourceOperationException::class)
            ->and(fn () => app(IncusSandboxHost::class)->execute($node, SandboxHostOperation::Provision, 'orbit-task-sandboxes', 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 4))
            ->toThrow(ResourceOperationException::class);
    });
});

describe('Incus guest commands', function (): void {
    it('keeps guest argv and binary input off host argv and returns the bounded guest result', function (): void {
        mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(function (SshConnection $connection, RemoteCommand $command): CommandResult {
            expect($command->arguments)->toBe(['/usr/local/bin/orbit-agent', 'sandbox'])
                ->and($command->maxOutputBytes)->toBe(12 * 1024 * 1024)->and($command->timeout)->toBe(60.0);
            $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            expect($request['operation'])->toBe('guest_command')
                ->and($request['guest'])->toBe(['role' => 'operator', 'argv' => ['bash', '-seu'], 'stdin' => base64_encode("printf secret\n"), 'timeout' => 30, 'max_output' => 100]);

            return new CommandResult(0, json_encode([
                'name' => 'ot-0a68f778a3', 'role' => 'operator', 'exit_code' => 7,
                'stdout' => base64_encode("\0output"), 'stderr' => base64_encode('failure'), 'duration_ms' => 12, 'truncated' => false, 'timed_out' => false,
            ], JSON_THROW_ON_ERROR), '', 1, false);
        });
        $result = app(IncusSandboxHost::class)->executeGuest(sandbox_host_node(), 'orbit-task-sandboxes', 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 4,
            new RemoteCommand(['bash', '-seu'], input: "printf secret\n", timeout: 30, maxOutputBytes: 100));
        expect($result->exitCode)->toBe(7)->and($result->stdout)->toBe("\0output")->and($result->stderr)->toBe('failure')->and($result->durationMs)->toBe(12)->and($result->truncated)->toBeFalse();
    });

    it('refuses invalid or substituted guest output without exposing it', function (array $changes): void {
        $data = array_replace([
            'name' => 'ot-0a68f778a3', 'role' => 'operator', 'exit_code' => 0,
            'stdout' => '', 'stderr' => '', 'duration_ms' => 12, 'truncated' => false, 'timed_out' => false,
        ], $changes);
        mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturn(new CommandResult(0, json_encode($data, JSON_THROW_ON_ERROR), '', 1, false));
        expect(fn () => app(IncusSandboxHost::class)->executeGuest(sandbox_host_node(), 'orbit-task-sandboxes', 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 4, new RemoteCommand(['id'], maxOutputBytes: 5)))
            ->toThrow(ResourceOperationException::class, 'invalid result');
    })->with([
        'different group' => [['name' => 'ot-foreign']],
        'different guest' => [['role' => 'gateway']],
        'oversized bytes' => [['stdout' => base64_encode('secret-over-limit')]],
        'invalid binary encoding' => [['stderr' => '@']],
        'false timeout success' => [['timed_out' => true]],
        'invalid exit code' => [['exit_code' => '0']],
    ]);

    it('rejects unsafe command envelopes before contacting a host', function (array $arguments, ?string $input, float $timeout, int $limit, string $role): void {
        mock(SshExecutor::class)->shouldReceive('execute')->never();
        expect(fn () => app(IncusSandboxHost::class)->executeGuest(sandbox_host_node(), 'orbit-task-sandboxes', 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 4,
            new RemoteCommand($arguments, input: $input, timeout: $timeout, maxOutputBytes: $limit), $role))
            ->toThrow(ResourceOperationException::class);
    })->with([
        'host role' => [['id'], null, 5, 100, 'host'],
        'NUL argv' => [["i\0d"], null, 5, 100, 'operator'],
        'long timeout' => [['id'], null, 901, 100, 'operator'],
        'large output' => [['id'], null, 5, 8388609, 'operator'],
        'large input' => [['id'], str_repeat('a', 524289), 5, 100, 'operator'],
    ]);
});

function project_identity_reservation(Node $host): TaskSandbox
{
    $project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Private Project VM', 'brief' => 'Verify owned Project SSH identity.', 'task_compute' => TaskCompute::Vm]);

    return TaskSandbox::query()->create([
        'id' => 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 'provider' => 'incus', 'name' => 'ot-0a68f778a3',
        'group_id' => $group->id, 'state' => SandboxState::Running, 'desired_power' => 'running',
        'spec' => ['host_id' => $host->id, 'project' => 'orbit-task-sandboxes', 'project_slug' => 'dlf',
            'images' => ['operator' => str_repeat('a', 64)], 'pool' => 'proof', 'subnet' => '10.233.201.0/24'],
    ]);
}

function project_identity_response(array $changes = []): CommandResult
{
    return new CommandResult(0, json_encode(array_replace([
        'name' => 'ot-0a68f778a3', 'guest' => 'ot-0a68f778a3-operator', 'project_slug' => 'dlf',
        'image' => str_repeat('a', 64), 'pool' => 'proof', 'subnet' => '10.233.201.0/24', 'address' => '10.233.201.10',
        'ssh_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHdUmJNAeflz28V7EadKJL3DLqnMqS6JyEQJmpCPNG5T',
    ], $changes), JSON_THROW_ON_ERROR), '', 1, false);
}

describe('owned Project SSH identity', function (): void {
    it('pins the exact Project guest key through the typed host channel without a network scan', function (): void {
        $host = sandbox_host_node();
        $sandbox = project_identity_reservation($host);
        mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturnUsing(function (SshConnection $connection, RemoteCommand $command): CommandResult {
            $request = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
            expect($connection->host)->toBe('10.44.0.20')
                ->and($command->arguments)->toBe(['/usr/local/bin/orbit-agent', 'sandbox'])
                ->and($request)->toBe(['operation' => 'project_identity', 'project' => 'orbit-task-sandboxes',
                    'sandbox_id' => 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 'budget' => 4]);

            return project_identity_response();
        });

        $key = app(IncusSandboxHost::class)->projectIdentity($host, $sandbox, 4);

        expect($key->type)->toBe('ssh-ed25519')
            ->and($key->value)->toBe('AAAAC3NzaC1lZDI1NTE5AAAAIHdUmJNAeflz28V7EadKJL3DLqnMqS6JyEQJmpCPNG5T')
            ->and($key->fingerprint)->toBe('SHA256:/Acun9GURd8wCZTgZWpQQE6ZGAhq6VnFN0vt4K3RZ3o')
            ->and($sandbox->fresh()->node_id)->toBeNull()->and($sandbox->fresh()->enrollment)->toBeNull();
    });

    it('refuses substituted ownership placement or key material', function (array $changes): void {
        $host = sandbox_host_node();
        $sandbox = project_identity_reservation($host);
        mock(SshExecutor::class)->shouldReceive('execute')->once()->andReturn(project_identity_response($changes));

        expect(fn () => app(IncusSandboxHost::class)->projectIdentity($host, $sandbox, 4))
            ->toThrow(ResourceOperationException::class)
            ->and($sandbox->fresh()->node_id)->toBeNull()->and($sandbox->fresh()->enrollment)->toBeNull();
    })->with([
        'another reservation' => [['name' => 'ot-foreign']],
        'another guest' => [['guest' => 'ot-0a68f778a3-gateway']],
        'another Project' => [['project_slug' => 'foreign']],
        'another image' => [['image' => str_repeat('b', 64)]],
        'another pool' => [['pool' => 'foreign']],
        'another subnet' => [['subnet' => '10.233.202.0/24']],
        'another address' => [['address' => '10.233.201.11']],
        'malformed key' => [['ssh_key' => 'ssh-ed25519 AA==']],
        'unsupported key' => [['ssh_key' => 'ssh-rsa AA==']],
        'unexpected private bytes' => [['private_key' => 'never-return-private-material']],
        'missing key' => [['ssh_key' => null]],
    ]);

    it('refuses a reservation outside initial local Project bootstrap before host IO', function (array $changes): void {
        $host = sandbox_host_node();
        $sandbox = project_identity_reservation($host);
        foreach ($changes as $field => $value) {
            if ($field === 'spec') {
                $sandbox->spec = array_replace($sandbox->spec, $value);
            } else {
                $sandbox->setAttribute($field, $value);
            }
        }
        mock(SshExecutor::class)->shouldReceive('execute')->never();

        expect(fn () => app(IncusSandboxHost::class)->projectIdentity($host, $sandbox, 4))->toThrow(ResourceOperationException::class);
    })->with([
        'cloud provider' => [['provider' => 'upcloud']],
        'stopped VM' => [['state' => SandboxState::Stopped]],
        'power transition' => [['desired_power' => 'stopped']],
        'existing Node' => [['node_id' => 99]],
        'existing enrollment' => [['enrollment' => ['node_id' => 99]]],
        'foreign host' => [['spec' => ['host_id' => 999]]],
        'Orbit image pair' => [['spec' => ['images' => ['operator' => str_repeat('a', 64), 'gateway' => str_repeat('b', 64)]]]],
        'changed Project' => [['spec' => ['project_slug' => 'orbit']]],
        'unallocated subnet' => [['spec' => ['subnet' => '10.44.0.0/24']]],
    ]);
});
