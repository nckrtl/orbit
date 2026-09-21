<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskPullRequestOpener;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use App\Models\TaskGroup;

final readonly class RemoteTaskPullRequestOpener implements TaskPullRequestOpener
{
    public function __construct(
        private AppDevSshExecutor $ssh,
    ) {}

    public function open(TaskGroup $group): ?string
    {
        $group->loadMissing(['app', 'taskable']);
        $instance = $group->taskable;

        if (! $instance instanceof AppInstance || $instance->checkout_path === '') {
            return null;
        }

        $instance->loadMissing('node');
        $head = $instance->branch !== null && $instance->branch !== ''
            ? $instance->branch
            : TaskWorkspaceName::for($group);
        $base = $group->app->default_branch !== null && $group->app->default_branch !== ''
            ? $group->app->default_branch
            : 'main';

        try {
            $result = $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: [
                        'bash',
                        '-seu',
                        '--',
                        $instance->checkout_path,
                        $group->title,
                        $group->brief,
                        $base,
                        $head,
                    ],
                    input: <<<'BASH'
                    checkout=$1
                    title=$2
                    body=$3
                    base=$4
                    head=$5
                    git -C "$checkout" push -u origin HEAD
                    cd "$checkout"
                    gh pr create --title "$title" --body "$body" --base "$base" --head "$head"
                    BASH,
                ),
                'task-pull-request-open',
                'tasks.pull_request_failed',
            );
        } catch (RuntimeConvergenceException) {
            return null;
        }

        foreach (array_reverse(preg_split('/\R/', trim($result->stdout)) ?: []) as $line) {
            if ($line !== '' && preg_match('#\Ahttps://github\.com/[^\s]+/pull/\d+\z#D', $line) === 1) {
                return $line;
            }
        }

        return null;
    }
}
