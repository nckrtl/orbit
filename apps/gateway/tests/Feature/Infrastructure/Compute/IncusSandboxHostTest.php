<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\SandboxHostOperation;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Models\Node;

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
