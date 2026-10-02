<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

final readonly class RemoteTaskWorkspaceSigner implements TaskWorkspaceSigner
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
    ) {}

    public function commit(Instance $instance, string $message): ?string
    {
        $instance->loadMissing('node');

        if ($instance->checkout_path === '' || $message === '') {
            return null;
        }

        try {
            $result = $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: TaskWorkerUser::arguments(['bash', '-seu', '--', $instance->checkout_path]),
                    input: "checkout=\$1\nmessage='".base64_encode($message)."'\n".<<<'BASH'
                    export GIT_AUTHOR_NAME=orbit
                    export GIT_AUTHOR_EMAIL=tasks@orbit
                    export GIT_COMMITTER_NAME=orbit
                    export GIT_COMMITTER_EMAIL=tasks@orbit
                    git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c safe.directory="$checkout" -C "$checkout" add -A
                    if ! git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c safe.directory="$checkout" -C "$checkout" diff --cached --quiet; then
                        printf '%s' "$message" | base64 -d | git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c safe.directory="$checkout" -C "$checkout" commit --quiet --file=-
                    fi
                    git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c safe.directory="$checkout" -C "$checkout" rev-parse --verify HEAD^{commit}
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
