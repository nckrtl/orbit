<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsRemoteCommand;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

describe(MetricsRemoteCommand::class, function (): void {
    it('gives a command without a timeout the default bound and keeps an explicit one', function (): void {
        $ssh = new MetricsRemoteCommandRecordingSsh;

        MetricsRemoteCommand::execute($ssh, metricsRemoteConnection(), metricsRemoteNode(), new RemoteCommand(['true']));
        MetricsRemoteCommand::execute($ssh, metricsRemoteConnection(), metricsRemoteNode(), new RemoteCommand(['true'], timeout: 300.0));

        expect(array_map(static fn (RemoteCommand $command): ?float => $command->timeout, $ssh->commands))
            ->toBe([MetricsRemoteCommand::DefaultTimeoutSeconds, 300.0]);
    });

    it('fails a command that runs out of time with a stable 504 that names the Node', function (): void {
        $ssh = new MetricsRemoteCommandRecordingSsh(timesOut: true);

        expect(fn () => MetricsRemoteCommand::execute($ssh, metricsRemoteConnection(), metricsRemoteNode(), new RemoteCommand(['sleep', '999'])))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('metrics.remote_command_timed_out')
                    ->and($exception->status)->toBe(504)
                    ->and($exception->getMessage())->toBe('A Metrics command on node [app-prod] did not finish within 120 seconds.');
            });
    });
});

function metricsRemoteNode(): Node
{
    return new Node(['name' => 'app-prod', 'wireguard_ip' => '10.44.0.4', 'user' => 'orbit']);
}

function metricsRemoteConnection(): SshConnection
{
    return new SshConnection('10.44.0.4', 'orbit', 22, '/tmp/key', '/tmp/known_hosts');
}

final class MetricsRemoteCommandRecordingSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    public function __construct(private readonly bool $timesOut = false) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;

        if ($this->timesOut) {
            throw new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL);
        }

        return new CommandResult(0, '', '', 1, false);
    }
}
