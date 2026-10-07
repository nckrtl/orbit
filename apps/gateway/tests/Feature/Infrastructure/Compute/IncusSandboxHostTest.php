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
