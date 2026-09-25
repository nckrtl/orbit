<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;

final readonly class RemoteTaskWorkspaceDiffReader implements TaskWorkspaceDiffReader
{
    public function __construct(
        private AppDevSshExecutor $ssh,
    ) {}

    public function lineDiff(AppInstance $instance, string $baseBranch): int
    {
        $changes = $this->lineChanges($instance, $baseBranch);

        return $changes === null ? 0 : $changes['additions'] + $changes['deletions'];
    }

    public function lineChanges(AppInstance $instance, string $baseBranch): ?array
    {
        $instance->loadMissing('node');

        if ($instance->checkout_path === '' || $baseBranch === '') {
            return null;
        }

        try {
            $result = $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: [
                        'bash',
                        '-seu',
                        '--',
                        $instance->checkout_path,
                        $baseBranch,
                    ],
                    input: <<<'BASH'
                    checkout=$1
                    base=$2
                    git -C "$checkout" diff --shortstat "$base"...HEAD
                    BASH,
                ),
                'task-workspace-diff',
                'tasks.diff_failed',
            );
        } catch (RuntimeConvergenceException) {
            return null;
        }

        // `--shortstat` prints one summary line, so a diff of any size stays under the SSH output cap.
        // Binary files count as changed with no lines, as they do in `--numstat`.
        $summary = trim($result->stdout);
        $additions = preg_match('/(\d+) insertions?\(\+\)/', $summary, $added) === 1 ? (int) $added[1] : 0;
        $deletions = preg_match('/(\d+) deletions?\(-\)/', $summary, $deleted) === 1 ? (int) $deleted[1] : 0;

        return ['additions' => $additions, 'deletions' => $deletions];
    }

    public function hasCommitsSince(AppInstance $instance, string $since): bool
    {
        $instance->loadMissing('node');

        if ($instance->checkout_path === '' || $since === '') {
            return false;
        }

        try {
            $result = $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: [
                        'bash',
                        '-seu',
                        '--',
                        $instance->checkout_path,
                        $since,
                    ],
                    input: <<<'BASH'
                    checkout=$1
                    since=$2
                    if git -C "$checkout" rev-parse --verify "$since" >/dev/null 2>&1; then
                        git -C "$checkout" rev-list --count "$since"..HEAD
                    else
                        git -C "$checkout" log --since="$since" --pretty=oneline
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
