<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use Symfony\Component\Process\Process;

/**
 * Runs each remote command in a local shell and models a root-only directory.
 *
 * A command that starts with `sudo` runs without that prefix and can read the directory. Any other
 * command runs while the directory denies access, as a root-owned 0750 directory does to the SSH user.
 * `sudo ufw status numbered` answers with the configured firewall status instead of the host's.
 */
final class LocalRootShellSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    public function __construct(
        private readonly string $rootOnlyDirectory,
        public string $ufwStatus = "Status: inactive\n",
        public int $ufwExitCode = 0,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $arguments = $command->arguments;

        if ($arguments === ['sudo', 'ufw', 'status', 'numbered']) {
            return new CommandResult($this->ufwExitCode, $this->ufwStatus, '', 1, false);
        }

        $privileged = $arguments[0] === 'sudo';

        if ($privileged) {
            array_shift($arguments);
        } else {
            chmod($this->rootOnlyDirectory, 0);
        }

        try {
            $process = new Process($arguments, null, null, $command->input);
            $process->run();
        } finally {
            chmod($this->rootOnlyDirectory, 0o750);
        }

        return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
    }
}
