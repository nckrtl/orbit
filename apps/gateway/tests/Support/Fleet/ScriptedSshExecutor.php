<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use Closure;

/**
 * Answers each remote command with the first scripted reply whose pattern matches the command line,
 * and succeeds with empty output otherwise. Records every command it ran.
 */
final class ScriptedSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<array{string, CommandResult|Closure(RemoteCommand): CommandResult}> */
    private array $replies = [];

    /** @param CommandResult|Closure(RemoteCommand): CommandResult $reply */
    public function on(string $pattern, CommandResult|Closure $reply): self
    {
        $this->replies[] = [$pattern, $reply];

        return $this;
    }

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $line = implode(' ', $command->arguments);

        foreach ($this->replies as [$pattern, $reply]) {
            if (preg_match($pattern, $line) === 1) {
                return $reply instanceof Closure ? $reply($command) : $reply;
            }
        }

        return self::ok();
    }

    public static function ok(string $stdout = ''): CommandResult
    {
        return new CommandResult(0, $stdout, '', 1, false);
    }

    public static function fail(int $exitCode = 1, string $stdout = '', string $stderr = ''): CommandResult
    {
        return new CommandResult($exitCode, $stdout, $stderr, 1, false);
    }

    /** @return list<string> */
    public function lines(): array
    {
        return array_map(static fn (RemoteCommand $command): string => implode(' ', $command->arguments), $this->commands);
    }
}
