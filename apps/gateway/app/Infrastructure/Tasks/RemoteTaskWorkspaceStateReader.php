<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

final readonly class RemoteTaskWorkspaceStateReader implements TaskWorkspaceStateReader
{
    public function __construct(private TaskWorkspaceExecutor $ssh) {}

    public function headCommit(Instance $instance): ?string
    {
        return $this->run($instance, 'git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c safe.directory="$checkout" -C "$checkout" rev-parse HEAD');
    }

    public function currentBranch(Instance $instance): ?string
    {
        return $this->run($instance, 'git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c safe.directory="$checkout" -C "$checkout" rev-parse --abbrev-ref HEAD');
    }

    private function run(Instance $instance, string $command): ?string
    {
        $instance->loadMissing('node');
        if ($instance->checkout_path === '') {
            return null;
        }
        try {
            $result = $this->ssh->execute($instance, new RemoteCommand(
                arguments: TaskWorkerUser::arguments(['bash', '-seu', '--', $instance->checkout_path], $instance),
                input: "checkout=\$1\n{$command}\n",
            ), 'task-workspace-state', 'tasks.diff_failed');
        } catch (RuntimeConvergenceException) {
            return null;
        }

        return trim($result->stdout);
    }
}
