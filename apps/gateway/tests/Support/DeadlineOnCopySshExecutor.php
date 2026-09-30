<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;

final class DeadlineOnCopySshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    public function __construct(private readonly string $head) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;

        if (in_array('--reflink=always', $command->arguments, true)) {
            throw new ResourceOperationException(
                'command.deadline_exceeded',
                'The 900-second command deadline was exceeded.',
                504,
            );
        }

        $input = $command->input ?? '';

        if (str_contains($input, 'instance.copy_source_cold')) {
            return new CommandResult(0, "ready\n{$this->head}\n", '', 1, false);
        }

        if (str_contains($input, 'marker_matches')) {
            return new CommandResult(0, "ready\n", '', 1, false);
        }

        return new CommandResult(0, '', '', 1, false);
    }
}
