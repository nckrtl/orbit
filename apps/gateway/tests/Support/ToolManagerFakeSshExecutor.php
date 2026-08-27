<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use LogicException;

final class ToolManagerFakeSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    /** @param list<CommandResult> $results */
    public function __construct(
        private array $results = [],
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $this->commands[] = $command;

        return (
            array_shift($this->results) ?? throw new LogicException(
                'The tool manager executed an unexpected SSH command.',
            )
        );
    }

    /** @return list<non-empty-list<string>> */
    public function arguments(): array
    {
        return array_map(
            static fn (RemoteCommand $command): array => $command->arguments,
            $this->commands,
        );
    }
}
