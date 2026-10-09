<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\SourceControl\WorkspaceGit;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\Task;
use SensitiveParameter;
use Throwable;

/**
 * Fetches `origin/{base}` with the pull request token. `{base}` is one argument. The fetch updates
 * the remote-tracking ref and does not check out or rebase the task branch (ADR 0164).
 * `fastForward` uses the refs already fetched for the turn and moves only a strictly behind workspace.
 * `fetchForTurn` fetches the default branch, `task-{id}`, and a different pull request base with the
 * read token and `--no-tags`. It updates remote-tracking refs and does not move HEAD.
 */
final readonly class GitHubTaskBaseBranchFetcher implements TaskBaseBranchFetcher
{
    public function __construct(
        private RepositoryPullRequestAccess $access,
        private RepositoryReadAccess $reads,
        private GitHubApi $github,
        private TaskWorkspaceExecutor $workspaces,
    ) {}

    public function fetch(Task $group, string $base): void
    {
        if (! GitBranchName::isValid($base)) {
            throw new TaskPullRequestException('The base branch could not be fetched.');
        }

        $group->loadMissing(['project', 'taskable']);
        $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
        $instance = $group->taskable;
        if (! $repository instanceof GitHubRepository || ! $instance instanceof Instance || $instance->checkout_path === ''
            || $instance->project_id !== $group->project_id
            || ($instance->task_sandbox_id !== null && $instance->taskSandbox?->group_id !== $group->id)
            || ($instance->task_sandbox_id === null && ! TaskVmPlacement::allowsWorkspace($group, $instance))) {
            throw new TaskPullRequestException('The base branch could not be fetched.');
        }

        try {
            $this->fetchRef($instance, $base, $this->access->token($repository));
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The base branch could not be fetched.', previous: $exception);
        }
    }

    public function fastForward(Task $group, bool $missingRefOk = false): void
    {
        $group->loadMissing(['project', 'taskable']);
        $instance = $group->taskable;
        if (! $instance instanceof Instance || $instance->checkout_path === '') {
            throw new TaskPullRequestException('The task branch could not be fetched.');
        }

        $instance->loadMissing('node');
        // The turn fetch has already updated this ref. This step is local and needs no token.
        $script = WorkspaceGit::bashPreamble().WorkspaceGit::workerPreamble(TaskWorkerUser::name($instance)).<<<'BASH'
            checkout=$1
            branch=$2
            status=0
            git -C "$checkout" show-ref --verify --quiet "refs/remotes/origin/$branch" || status=$?
            if [ "$status" -eq 1 ] && [ "${3:-}" = "missing-ok" ]; then
                exit 0
            fi
            if [ "$status" -ne 0 ]; then
                exit "$status"
            fi
            head=$(git -C "$checkout" rev-parse HEAD)
            remote=$(git -C "$checkout" rev-parse "refs/remotes/origin/$branch")
            if [ "$head" != "$remote" ] && git -C "$checkout" merge-base --is-ancestor "$head" "$remote"; then
                workspace_git -C "$checkout" merge --ff-only --quiet "$remote"
            fi
            BASH;
        $arguments = ['bash', '-seu', '--', $instance->checkout_path, TaskRemoteBranch::for($group)];
        if ($missingRefOk) {
            $arguments[] = 'missing-ok';
        }
        try {
            $this->workspaces->execute($instance, new RemoteCommand(
                arguments: $arguments,
                input: $script,
            ), 'task-branch-sync', 'tasks.fetch_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException('The task branch could not be fetched.', previous: $exception);
        }
    }

    public function resetToDefault(Task $group): string
    {
        $group->loadMissing(['project', 'taskable']);
        $instance = $group->taskable;
        $default = $group->project->default_branch;
        $local = $instance instanceof Instance && is_string($instance->branch) && $instance->branch !== '' ? $instance->branch : 'task-'.$group->id;
        if (! $instance instanceof Instance || $instance->checkout_path === ''
            || ! is_string($default) || ! GitBranchName::isValid($default) || ! GitBranchName::isValid($local)) {
            throw new TaskPullRequestException('The baseline workspace could not be reset.');
        }
        $instance->loadMissing('node');
        // The checks and the reset run in one command: another branch, a commit that is not on the
        // default branch, or a tracked change is manual work, and the reset refuses it. Content reads
        // can start filters, so they run as the worker.
        $script = WorkspaceGit::bashPreamble().WorkspaceGit::workerPreamble(TaskWorkerUser::name($instance)).<<<'BASH'
            checkout=$1
            branch=$2
            local=$3
            tip=$(git -C "$checkout" rev-parse --verify "refs/remotes/origin/$branch^{commit}")
            if [ "$(git -C "$checkout" symbolic-ref -q HEAD)" != "refs/heads/$local" ] \
                || ! git -C "$checkout" merge-base --is-ancestor HEAD "$tip" \
                || ! workspace_git -C "$checkout" diff --quiet --cached HEAD -- \
                || ! workspace_git -C "$checkout" diff --quiet --; then
                echo 'The workspace has manual work.' >&2
                exit 3
            fi
            workspace_git -C "$checkout" reset --hard --quiet "$tip"
            git -C "$checkout" rev-parse HEAD
            BASH;
        try {
            $result = $this->workspaces->execute($instance, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, $default, $local],
                input: $script,
            ), 'task-baseline-reset', 'tasks.baseline_reset_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException('The baseline workspace could not be reset.', previous: $exception);
        }
        $head = trim($result->stdout);
        if (preg_match('/\\A[0-9a-f]{40}\\z/', $head) !== 1) {
            throw new TaskPullRequestException('The baseline workspace start commit could not be read.');
        }

        return $head;
    }

    public function mergeBase(Task $group): string
    {
        $instance = $this->workspace($group);
        $default = $group->project->default_branch;
        if (! is_string($default) || ! GitBranchName::isValid($default)) {
            throw new TaskPullRequestException('The merge base could not be read.');
        }
        $script = WorkspaceGit::bashPreamble().<<<'BASH'
            checkout=$1
            branch=$2
            git -C "$checkout" merge-base HEAD "refs/remotes/origin/$branch"
            BASH;

        return $this->commitFrom($instance, $script, [$default], 'task-merge-base', 'The merge base could not be read.');
    }

    public function moveTo(Task $group, string $sha): void
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) {
            throw new TaskPullRequestException('The pull request head is not a Git SHA.');
        }
        $instance = $this->workspace($group);
        // Only a workspace without tracked changes moves. Untracked Orbit files such as .mcp.json stay.
        $script = WorkspaceGit::bashPreamble().WorkspaceGit::workerPreamble(TaskWorkerUser::name($instance)).<<<'BASH'
            checkout=$1
            sha=$2
            git -C "$checkout" cat-file -e "$sha^{commit}"
            git -C "$checkout" diff --quiet HEAD --
            workspace_git -C "$checkout" reset --hard --quiet "$sha"
            git -C "$checkout" rev-parse HEAD
            BASH;
        if ($this->commitFrom($instance, $script, [$sha], 'task-pull-request-head', 'The workspace could not move to the pull request head.') !== $sha) {
            throw new TaskPullRequestException('The workspace could not move to the pull request head.');
        }
    }

    /**
     * @param  list<string>  $arguments
     *
     * @throws TaskPullRequestException
     */
    private function commitFrom(Instance $instance, string $script, array $arguments, string $step, string $failure): string
    {
        try {
            $result = $this->workspaces->execute($instance, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, ...$arguments],
                input: $script,
            ), $step, 'tasks.fetch_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException($failure, previous: $exception);
        }
        $commit = trim($result->stdout);
        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            throw new TaskPullRequestException($failure);
        }

        return $commit;
    }

    /** @throws TaskPullRequestException */
    private function workspace(Task $group): Instance
    {
        $group->loadMissing(['project', 'taskable']);
        $instance = $group->taskable;
        if (! $instance instanceof Instance || $instance->checkout_path === '' || $instance->project_id !== $group->project_id) {
            throw new TaskPullRequestException('The task workspace is unavailable.');
        }
        $instance->loadMissing('node');

        return $instance;
    }

    public function fetchForTurn(Task $group): void
    {
        try {
            $this->fetchTurnRefs($group);
        } catch (TaskPullRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.', previous: $exception);
        }
    }

    /**
     * @throws TaskPullRequestException
     */
    private function fetchTurnRefs(Task $group): void
    {
        $group->loadMissing(['project', 'taskable']);
        $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
        $instance = $group->taskable;
        $default = $group->project->default_branch;
        if (! $repository instanceof GitHubRepository || ! $instance instanceof Instance || $instance->checkout_path === ''
            || $instance->project_id !== $group->project_id
            || ($instance->task_sandbox_id !== null && $instance->taskSandbox?->group_id !== $group->id)
            || ($instance->task_sandbox_id === null && ! TaskVmPlacement::allowsWorkspace($group, $instance))
            || ! is_string($default) || ! GitBranchName::isValid($default)) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.');
        }
        $taskBranch = TaskRemoteBranch::for($group);
        if (! GitBranchName::isValid($taskBranch)) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.');
        }

        try {
            $environment = $instance->task_sandbox_id !== null
                ? GitReadEnvironment::forGitHubToken($this->access->readToken($repository))
                : $this->reads->for((string) $group->project->repository_url, $group->project->source_access);
        } catch (ResourceOperationException $exception) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.', previous: $exception);
        }

        $pullBase = $this->pullRequestBase($group, $repository, $default, $taskBranch);
        $this->fetchRefs($instance, $environment, $default, $taskBranch, $pullBase);
    }

    /**
     * The pull request base when the turn should fetch it, or null when it is absent or already included.
     * The base name is read on the Gateway. The git fetch itself uses the read token.
     *
     * @throws TaskPullRequestException
     */
    private function pullRequestBase(Task $group, GitHubRepository $repository, string $default, string $taskBranch): ?string
    {
        if (! is_string($group->pr_url) || $group->pr_url === '') {
            return null;
        }
        $number = $repository->pullRequestNumber($group->pr_url);
        if ($number === null) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.');
        }

        try {
            $base = $this->github->pullRequest($this->access->token($repository), $repository, $number)->baseRef;
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.', previous: $exception);
        }
        if (! is_string($base) || $base === '' || $base === $default || $base === $taskBranch) {
            return null;
        }
        if (! GitBranchName::isValid($base)) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.');
        }

        return $base;
    }

    private function fetchRefs(Instance $instance, GitReadEnvironment $environment, string $default, string $taskBranch, ?string $pullBase): void
    {
        $instance->loadMissing('node');
        // A missing task branch is not a failure. The full ref avoids a suffix such as archive/task-{id}.
        // The other refs are still fetched, and HEAD stays put.
        $script = GitReadScript::for($environment, <<<'BASH'
            checkout=$1
            default_branch=$2
            task_branch=$3
            pull_base=$4
            status=0
            git_read git -c credential.helper= -c http.followRedirects=false -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" ls-remote --exit-code --heads origin "refs/heads/$task_branch" >/dev/null || status=$?
            if [ "$status" -ne 0 ] && [ "$status" -ne 2 ]; then
                exit "$status"
            fi
            refspecs=("+refs/heads/${default_branch}:refs/remotes/origin/${default_branch}")
            if [ "$status" -eq 0 ]; then
                refspecs+=("+refs/heads/${task_branch}:refs/remotes/origin/${task_branch}")
            fi
            if [ -n "$pull_base" ]; then
                refspecs+=("+refs/heads/${pull_base}:refs/remotes/origin/${pull_base}")
            fi
            git_read git -c credential.helper= -c http.followRedirects=false -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" fetch --no-tags --quiet origin "${refspecs[@]}"
            if [ "$status" -eq 2 ]; then
                git -C "$checkout" update-ref -d "refs/remotes/origin/$task_branch"
            fi
            BASH);
        try {
            $this->workspaces->execute($instance, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, $default, $taskBranch, $pullBase ?? ''],
                input: $script->input,
                protectedInput: $script->protectedInput,
            ), 'task-turn-fetch', 'tasks.fetch_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.', previous: $exception);
        }
    }

    private function fetchRef(Instance $instance, string $base, #[SensitiveParameter] string $token): void
    {
        $instance->loadMissing('node');
        $script = GitReadScript::for(GitReadEnvironment::forGitHubToken($token), <<<'BASH'
            checkout=$1
            base=$2
            git_read git -c credential.helper= -c http.followRedirects=false -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" fetch --quiet origin "$base"
            BASH);
        try {
            $this->workspaces->execute($instance, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, $base],
                input: $script->input,
                protectedInput: $script->protectedInput,
            ), 'task-base-fetch', 'tasks.fetch_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException('The base branch could not be fetched.', previous: $exception);
        }
    }
}
