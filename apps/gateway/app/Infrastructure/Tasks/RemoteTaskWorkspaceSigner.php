<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;

final readonly class RemoteTaskWorkspaceSigner implements TaskWorkspaceSigner
{
    public function __construct(
        private AppDevSshExecutor $ssh,
    ) {}

    public function commit(AppInstance $instance, string $message): ?string
    {
        $instance->loadMissing('node');

        if ($instance->checkout_path === '' || $message === '') {
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
                        $message,
                    ],
                    input: <<<'BASH'
                    checkout=$1
                    message=$2
                    export GIT_AUTHOR_NAME=orbit
                    export GIT_AUTHOR_EMAIL=tasks@orbit
                    export GIT_COMMITTER_NAME=orbit
                    export GIT_COMMITTER_EMAIL=tasks@orbit
                    git -C "$checkout" add -A
                    if ! git -C "$checkout" diff --cached --quiet; then
                        git -C "$checkout" commit --quiet -m "$message"
                    fi
                    git -C "$checkout" rev-parse --verify HEAD^{commit}
                    BASH,
                ),
                'task-workspace-commit',
                'tasks.commit_failed',
            );
        } catch (RuntimeConvergenceException) {
            return null;
        }

        $sha = trim($result->stdout);

        if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $sha) !== 1) {
            return null;
        }

        return $sha;
    }
}
