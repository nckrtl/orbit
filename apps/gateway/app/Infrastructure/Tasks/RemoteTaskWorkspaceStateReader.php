<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;

final readonly class RemoteTaskWorkspaceStateReader implements TaskWorkspaceStateReader
{
    public function __construct(private AppDevSshExecutor $ssh) {}

    public function headCommit(AppInstance $instance): ?string
    {
        return $this->git($instance, 'rev-parse HEAD');
    }

    public function currentBranch(AppInstance $instance): ?string
    {
        return $this->git($instance, 'rev-parse --abbrev-ref HEAD');
    }

    public function isClean(AppInstance $instance): bool
    {
        return $this->git($instance, 'status --porcelain --untracked-files=all') === '';
    }

    private function git(AppInstance $instance, string $command): ?string
    {
        $instance->loadMissing('node');
        if ($instance->checkout_path === '') {
            return null;
        }
        try {
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path],
                input: "checkout=\$1\ngit -C \"\$checkout\" {$command}\n",
            ), 'task-workspace-state', 'tasks.diff_failed');
        } catch (RuntimeConvergenceException) {
            return null;
        }

        return trim($result->stdout);
    }
}
