<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskWorkspaceCommitReader;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

final readonly class RemoteTaskWorkspaceCommitReader implements TaskWorkspaceCommitReader
{
    private const int CommitLimit = 200;

    public function __construct(
        private AppDevSshExecutor $ssh,
    ) {}

    public function commitTimes(AppInstance $instance): ?array
    {
        $instance->loadMissing('node');

        if ($instance->checkout_path === '') {
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
                        (string) self::CommitLimit,
                    ],
                    input: <<<'BASH'
                    checkout=$1
                    limit=$2
                    git -C "$checkout" log --max-count="$limit" --format=%cI HEAD
                    BASH,
                ),
                'task-workspace-commits',
                'tasks.commits_failed',
            );
        } catch (RuntimeConvergenceException) {
            return null;
        }

        $times = [];

        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                $times[] = CarbonImmutable::parse($line);
            } catch (InvalidFormatException) {
                continue;
            }
        }

        return $times;
    }
}
