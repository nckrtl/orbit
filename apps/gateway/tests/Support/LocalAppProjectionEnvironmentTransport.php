<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Instances\AppProjectionEnvironmentProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use Symfony\Component\Process\Process;

/** Real filesystem program, with only SSH/sudo and the privileged receipt root substituted. */
final class LocalAppProjectionEnvironmentTransport implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    public bool $loseAcknowledgment = false;

    public ?string $lastInput = null;

    public function __construct(private readonly string $root) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        if ($command->arguments !== ['sudo', 'python3', '-c', AppProjectionEnvironmentProgram::script()] || $command->protectedInput === null || $command->input !== null) {
            throw new \RuntimeException('Unexpected environment transport.');
        }
        $input = stream_get_contents($command->protectedInput->stream());
        if (! is_string($input)) {
            throw new \RuntimeException('Missing protected input.');
        }
        $this->lastInput = $input;
        $program = str_replace('/var/lib/orbit/app-environments', $this->root.'/receipts', AppProjectionEnvironmentProgram::script());
        $process = new Process(['python3', '-c', $program]);
        $process->setInput($input);
        $process->run();
        $exitCode = $process->getExitCode() ?? 1;
        if ($this->loseAcknowledgment && $exitCode === 0) {
            $this->loseAcknowledgment = false;
            $exitCode = 255;
        }

        return new CommandResult($exitCode, $process->getOutput(), $process->getErrorOutput(), 0, false);
    }
}
