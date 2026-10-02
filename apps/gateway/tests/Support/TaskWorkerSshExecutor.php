<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use Symfony\Component\Process\Process;

/** Uses real sudo and nobody, with an isolated global Git trust list instead of changing that shared account's home. */
final readonly class TaskWorkerSshExecutor implements SshExecutor
{
    private function __construct(private string $config) {}

    public static function forCheckout(string $checkout): self
    {
        $config = $checkout.'/.git/worker-global-config';
        file_put_contents($config, "[safe]\n\tdirectory = {$checkout}\n");

        return new self($config);
    }

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $arguments = array_map(static fn (string $argument): string => str_replace('sudo -n', 'sudo --preserve-env=GIT_CONFIG_GLOBAL -n', $argument), $command->arguments);
        if ($arguments[0] === 'sudo') {
            array_splice($arguments, 1, 0, ['--preserve-env=GIT_CONFIG_GLOBAL']);
        }
        $program = $command->protectedInput instanceof ProtectedInput ? stream_get_contents($command->protectedInput->stream()) : $command->input;
        $input = str_replace('sudo -n', 'sudo --preserve-env=GIT_CONFIG_GLOBAL -n', $program ?? '');
        $process = new Process($arguments, null, ['GIT_CONFIG_GLOBAL' => $this->config], $input);
        $process->run();

        return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
    }
}
