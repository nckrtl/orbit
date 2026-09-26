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
