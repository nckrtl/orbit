<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubPullRequestDraft;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use App\Models\TaskGroup;

/**
 * The token reaches the Node only on the SSH process's standard input, as for a read
 * ([ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app)).
 */
final readonly class GitHubTaskPullRequestPublisher implements TaskPullRequestPublisher
{
    public function __construct(
        private RepositoryPullRequestAccess $access,
        private GitHubApi $github,
        private AppDevSshExecutor $ssh,
    ) {}

    public function publish(TaskGroup $group, string $body): string
    {
        [$repository, $instance, $branch] = $this->target($group);
        $base = $group->app->default_branch;
        if (! is_string($base) || ! GitBranchName::isValid($base)) {
            throw new TaskPullRequestException('The Project has no valid default branch.');
        }

        try {
            $token = $this->access->token($repository);
            $this->pushBranch($instance, $branch, $token);

            return $this->github->openPullRequest($token, $repository, new GitHubPullRequestDraft($branch, $base, $group->title, $body));
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The pull request could not be opened: '.$exception->getMessage(), previous: $exception);
        }
    }

    public function push(TaskGroup $group): void
    {
        [$repository, $instance, $branch] = $this->target($group);

        try {
            $this->pushBranch($instance, $branch, $this->access->token($repository));
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The task branch could not be pushed: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return array{GitHubRepository, AppInstance, string}
     *
     * @throws TaskPullRequestException
     */
    private function target(TaskGroup $group): array
    {
        $group->loadMissing(['app', 'taskable']);
        $repository = GitHubRepository::fromOrigin((string) $group->app->repository_url);
        if (! $repository instanceof GitHubRepository) {
            throw new TaskPullRequestException('The Project repository is not on github.com.');
        }
        $instance = $group->taskable;
        if (! $instance instanceof AppInstance || $instance->checkout_path === '') {
            throw new TaskPullRequestException('The task workspace is unavailable.');
        }

        return [$repository, $instance, 'task-'.$group->id];
    }

    private function pushBranch(AppInstance $instance, string $branch, string $token): void
    {
        $instance->loadMissing('node');
        $script = GitReadScript::for(GitReadEnvironment::forGitHubToken($token), <<<'BASH'
            checkout=$1
            branch=$2
            git_read git -C "$checkout" push --quiet origin "HEAD:refs/heads/$branch"
            BASH);
        try {
            $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, $branch],
                input: $script->input,
                protectedInput: $script->protectedInput,
            ), 'task-pull-request-push', 'tasks.push_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException('The task branch could not be pushed.', previous: $exception);
        }
    }
}
