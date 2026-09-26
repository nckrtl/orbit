<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Runs one Metrics command on a Node within a bound.
 *
 * Metrics convergence runs inside role, Route, and Instance requests, so a stuck command must fail
 * that request with a stable error long before PHP-FPM ends it. A command without its own timeout
 * gets `DefaultTimeoutSeconds`; a download sets its own, longer bound.
 */
final readonly class MetricsRemoteCommand
{
    public const float DefaultTimeoutSeconds = 120.0;

    /** The bound for a package or binary download, which only a Node's first convergence needs. */
    public const float DownloadTimeoutSeconds = 300.0;

    public static function execute(
        SshExecutor $ssh,
        SshConnection $connection,
        Node $node,
        RemoteCommand $command,
        string $timeoutErrorCode = 'metrics.remote_command_timed_out',
    ): CommandResult {
        $timeout = $command->timeout ?? self::DefaultTimeoutSeconds;

        try {
            return $ssh->execute($connection, self::bounded($command, $timeout));
        } catch (ProcessTimedOutException $exception) {
            throw new ResourceOperationException(
                $timeoutErrorCode,
                sprintf('A Metrics command on node [%s] did not finish within %d seconds.', $node->name, (int) $timeout),
                504,
                $exception,
            );
        }
    }

    private static function bounded(RemoteCommand $command, float $timeout): RemoteCommand
    {
        if ($command->timeout !== null) {
            return $command;
        }

        return new RemoteCommand(
            $command->arguments,
            input: $command->input,
            protectedInput: $command->protectedInput,
            maxOutputBytes: $command->maxOutputBytes,
            output: $command->output,
            cancelled: $command->cancelled,
            timeout: $timeout,
            terminateGraceSeconds: $command->terminateGraceSeconds,
        );
    }
}
