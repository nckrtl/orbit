<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

final readonly class RemoteTaskWorkspaceDiffReader implements TaskWorkspaceDiffReader
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
    ) {}

    public function lineDiff(Instance $instance, string $baseBranch): int
    {
        $changes = $this->lineChanges($instance, $baseBranch);

        return $changes === null ? 0 : $changes['additions'] + $changes['deletions'];
    }

    public function lineChanges(Instance $instance, string $baseBranch): ?array
    {
        $instance->loadMissing('node');

        if ($instance->checkout_path === '' || $baseBranch === '') {
            return null;
        }

        try {
            $result = $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: TaskWorkerUser::arguments([
                        'bash',
                        '-seu',
                        '--',
                        $instance->checkout_path,
                        $baseBranch,
                    ]),
                    input: <<<'BASH'
                    checkout=$1
                    base=$2
                    git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c safe.directory="$checkout" -C "$checkout" diff --shortstat "refs/remotes/origin/$base"...HEAD
                    BASH,
                ),
                'task-workspace-diff',
                'tasks.diff_failed',
            );
        } catch (RuntimeConvergenceException) {
            return null;
        }

        // The diff counts against the fetched base, because a merge of origin/{base} leaves the local base branch behind.
        // `--shortstat` prints one summary line, so a diff of any size stays under the SSH output cap.
        // Binary files count as changed with no lines, as they do in `--numstat`.
        $summary = trim($result->stdout);
        $additions = preg_match('/(\d+) insertions?\(\+\)/', $summary, $added) === 1 ? (int) $added[1] : 0;
        $deletions = preg_match('/(\d+) deletions?\(-\)/', $summary, $deleted) === 1 ? (int) $deleted[1] : 0;

        return ['additions' => $additions, 'deletions' => $deletions];
    }

    public function hasCommitsSince(Instance $instance, string $since): bool
    {
        $instance->loadMissing('node');

        if ($instance->checkout_path === '' || $since === '') {
            return false;
        }

        try {
            $result = $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: TaskWorkerUser::arguments([
                        'bash',
                        '-seu',
                        '--',
                        $instance->checkout_path,
                        $since,
                    ]),
                    input: <<<'BASH'
                    checkout=$1
                    since=$2
                    if git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c safe.directory="$checkout" -C "$checkout" rev-parse --verify "$since" >/dev/null 2>&1; then
                        git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c safe.directory="$checkout" -C "$checkout" rev-list --count "$since"..HEAD
                    else
                        git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c safe.directory="$checkout" -C "$checkout" log --since="$since" --pretty=oneline
                    fi
                    BASH,
                ),
                'task-workspace-commits',
                'tasks.diff_failed',
            );
        } catch (RuntimeConvergenceException) {
            return false;
        }

        $stdout = trim($result->stdout);

        if ($stdout === '' || $stdout === '0') {
            return false;
        }

        return true;
    }
}
