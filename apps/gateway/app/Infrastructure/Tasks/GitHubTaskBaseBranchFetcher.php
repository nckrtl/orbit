<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskPullRequestException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use App\Models\TaskGroup;

/**
 * Fetches `origin/{base}` with the pull request token. `{base}` is one argument. The fetch updates
 * the remote-tracking ref and does not check out or rebase the task branch (ADR 0164).
 * `fastForward` catches the workspace up with `origin/task-{group id}` only when it is strictly behind.
 */
final readonly class GitHubTaskBaseBranchFetcher implements TaskBaseBranchFetcher
{
    public function __construct(
        private RepositoryPullRequestAccess $access,
        private AppDevSshExecutor $ssh,
    ) {}

    public function fetch(TaskGroup $group, string $base): void
    {
        if (! GitBranchName::isValid($base)) {
            throw new TaskPullRequestException('The base branch could not be fetched.');
        }

        $group->loadMissing(['app', 'taskable']);
        $repository = GitHubRepository::fromOrigin((string) $group->app->repository_url);
        $instance = $group->taskable;
        if (! $repository instanceof GitHubRepository || ! $instance instanceof AppInstance || $instance->checkout_path === '') {
            throw new TaskPullRequestException('The base branch could not be fetched.');
        }

        try {
            $this->fetchRef($instance, $base, $this->access->token($repository));
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The base branch could not be fetched.', previous: $exception);
        }
    }

    public function fastForward(TaskGroup $group, bool $missingRefOk = false): void
    {
        $group->loadMissing(['app', 'taskable']);
        $repository = GitHubRepository::fromOrigin((string) $group->app->repository_url);
        $instance = $group->taskable;
        if (! $repository instanceof GitHubRepository || ! $instance instanceof AppInstance || $instance->checkout_path === '') {
            throw new TaskPullRequestException('The task branch could not be fetched.');
        }

        try {
            $token = $this->access->token($repository);
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The task branch could not be fetched.', previous: $exception);
        }

        $instance->loadMissing('node');
        // The remote-tracking ref may be replaced; the workspace only moves by a fast-forward merge.
        // A missing task branch is left alone when the caller allows it, and is still a failure otherwise.
        $script = GitReadScript::for(GitReadEnvironment::forGitHubToken($token), <<<'BASH'
            checkout=$1
            branch=$2
            if [ "${3:-}" = "missing-ok" ]; then
                status=0
                git_read git -C "$checkout" ls-remote --exit-code --heads origin "$branch" >/dev/null || status=$?
                if [ "$status" -eq 2 ]; then
                    exit 0
                fi
                if [ "$status" -ne 0 ]; then
                    exit "$status"
                fi
            fi
            git_read git -C "$checkout" fetch --quiet origin "+refs/heads/$branch:refs/remotes/origin/$branch"
            head=$(git -C "$checkout" rev-parse HEAD)
            remote=$(git -C "$checkout" rev-parse "refs/remotes/origin/$branch")
            if [ "$head" != "$remote" ] && git -C "$checkout" merge-base --is-ancestor "$head" "$remote"; then
                git -C "$checkout" merge --ff-only --quiet "$remote"
            fi
            BASH);
        $arguments = ['bash', '-seu', '--', $instance->checkout_path, 'task-'.$group->id];
        if ($missingRefOk) {
            $arguments[] = 'missing-ok';
        }
        try {
            $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: $arguments,
                input: $script->input,
                protectedInput: $script->protectedInput,
            ), 'task-branch-sync', 'tasks.fetch_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException('The task branch could not be fetched.', previous: $exception);
        }
    }

    private function fetchRef(AppInstance $instance, string $base, string $token): void
    {
        $instance->loadMissing('node');
        $script = GitReadScript::for(GitReadEnvironment::forGitHubToken($token), <<<'BASH'
            checkout=$1
            base=$2
            git_read git -C "$checkout" fetch --quiet origin "$base"
            BASH);
        try {
            $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, $base],
                input: $script->input,
                protectedInput: $script->protectedInput,
            ), 'task-base-fetch', 'tasks.fetch_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException('The base branch could not be fetched.', previous: $exception);
        }
    }
}
