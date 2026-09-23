<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use Symfony\Component\Process\Process;

/**
 * Runs each remote command in a local shell, so tests exercise the real remote program.
 */
final class LocalShellSshExecutor implements SshExecutor
{
    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $input = $command->protectedInput instanceof ProtectedInput ? stream_get_contents($command->protectedInput->stream()) : $command->input;
        $process = new Process($command->arguments, null, null, $input);
        $process->run();

        return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
    }
}
