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
                    git -C "$checkout" diff --numstat "$base"...HEAD
                    BASH,
                ),
                'task-workspace-diff',
                'tasks.diff_failed',
            );
        } catch (RuntimeConvergenceException) {
            return null;
        }

        $additions = 0;
        $deletions = 0;

        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/\A(\d+|-)\t(\d+|-)\t/D', $line, $matches) !== 1) {
                continue;
            }

            $additions += $matches[1] === '-' ? 0 : (int) $matches[1];
            $deletions += $matches[2] === '-' ? 0 : (int) $matches[2];
        }

        return ['additions' => $additions, 'deletions' => $deletions];
    }
}
