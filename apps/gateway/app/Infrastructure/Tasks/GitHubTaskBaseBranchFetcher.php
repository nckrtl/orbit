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
use App\Domain\Tasks\TaskWorkspaceSnapshot;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\SourceControl\WorkspaceGit;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\Task;
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
        private DevelopmentSshExecutor $ssh,
    ) {}

    public function fetch(Task $group, string $base): void
    {
        if (! GitBranchName::isValid($base)) {
            throw new TaskPullRequestException('The base branch could not be fetched.');
        }

        $group->loadMissing(['project', 'taskable']);
        $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
        $instance = $group->taskable;
        if (! $repository instanceof GitHubRepository || ! $instance instanceof Instance || $instance->checkout_path === '') {
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
        $script = WorkspaceGit::bashPreamble().WorkspaceGit::workerPreamble(TaskWorkerUser::name()).<<<'BASH'
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
        $arguments = ['bash', '-seu', '--', $instance->checkout_path, 'task-'.$group->id];
        if ($missingRefOk) {
            $arguments[] = 'missing-ok';
        }
        try {
            $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: $arguments,
                input: $script,
            ), 'task-branch-sync', 'tasks.fetch_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException('The task branch could not be fetched.', previous: $exception);
        }
    }

    public function resetToDefault(Task $group, ?string $verifiedTip = null): string
    {
        $group->loadMissing(['project', 'taskable']);
        $instance = $group->taskable;
        $default = $group->project->default_branch;
        if (! $instance instanceof Instance || $instance->checkout_path === ''
            || ! is_string($default) || ! GitBranchName::isValid($default)) {
            throw new TaskPullRequestException('The baseline workspace could not be reset.');
        }
        if ($verifiedTip !== null && preg_match('/\A[0-9a-f]{40}\z/', $verifiedTip) !== 1) {
            throw new TaskPullRequestException('The verified baseline tip is invalid.');
        }
        $instance->loadMissing('node');
        $script = WorkspaceGit::bashPreamble().WorkspaceGit::workerPreamble(TaskWorkerUser::name()).<<<'BASH'
            checkout=$1
            branch=$2
            verified=$3
            tip=$(git -C "$checkout" rev-parse --verify "refs/remotes/origin/$branch^{commit}")
            if [ -n "$verified" ]; then
                [ "$tip" = "$verified" ] || exit 1
                tip=$verified
            fi
            workspace_git -C "$checkout" reset --hard --quiet "$tip"
            git -C "$checkout" rev-parse HEAD
            BASH;
        try {
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, $default, $verifiedTip ?? ''],
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

    public function advanceCandidate(Task $group, string $head, string $tree, string $target, string $indexTree): TaskWorkspaceSnapshot
    {
        $group->loadMissing(['project', 'taskable.node']);
        $instance = $group->taskable;
        $default = $group->project->default_branch;
        if (! $instance instanceof Instance || $instance->checkout_path === '' || ! is_string($default) || ! GitBranchName::isValid($default)) {
            throw new TaskPullRequestException('The candidate workspace is unavailable.');
        }
        $worker = TaskWorkerUser::name();
        if ($worker === null || ! is_string($instance->branch) || ! GitBranchName::isValid($instance->branch)) {
            throw new TaskPullRequestException('The candidate worker or branch is unavailable.');
        }
        $script = file_get_contents(resource_path('tasks/advance-candidate'));
        if (! is_string($script)) {
            throw new TaskPullRequestException('The candidate advancement resource is unavailable.');
        }
        try {
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['sudo', '-n', '-u', $worker, '-H', 'python3', '-', $instance->checkout_path, $head, $tree, $target, $instance->branch, $default, $indexTree],
                input: $script,
            ), 'task-candidate-advance', 'tasks.candidate_advance_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException('The pinned candidate fast-forward was refused; preserve the workspace for direction.', previous: $exception);
        }
        $value = json_decode($result->stdout, true);
        if (! is_array($value) || ($value['head'] ?? null) !== $target || ! is_string($value['tree'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $value['tree']) !== 1 || ($value['branch'] ?? null) !== $instance->branch
            || ! is_string($value['index_tree'] ?? null) || preg_match('/\A[0-9a-f]{40}\z/', $value['index_tree']) !== 1) {
            throw new TaskPullRequestException('The candidate advancement result is uncertain; reconcile the workspace before continuing.');
        }

        return new TaskWorkspaceSnapshot($target, $value['tree'], branch: $value['branch'], indexTree: $value['index_tree']);
    }

    public function defaultTip(Task $group): string
    {
        $group->loadMissing('project');
        $default = $group->project->default_branch;
        if (! is_string($default) || ! GitBranchName::isValid($default)) {
            throw new TaskPullRequestException('The default branch tip could not be read.');
        }
        $tip = $this->readWorkspace($group, <<<'BASH'
            git -C "$1" rev-parse --verify "refs/remotes/origin/$2^{commit}"
            BASH, [$default]);
        if (preg_match('/\A[0-9a-f]{40}\z/', $tip) !== 1) {
            throw new TaskPullRequestException('The default branch tip could not be read.');
        }

        return $tip;
    }

    public function isAncestor(Task $group, string $ancestor, string $tip): bool
    {
        foreach ([$ancestor, $tip] as $sha) {
            if (preg_match('/\A[0-9a-f]{40}\z/', $sha) !== 1) {
                throw new TaskPullRequestException('The baseline ancestry could not be read.');
            }
        }

        return $this->readWorkspace($group, <<<'BASH'
            status=0
            git -C "$1" merge-base --is-ancestor "$2" "$3" || status=$?
            case "$status" in
                0) printf 'yes' ;;
                1) printf 'no' ;;
                *) exit "$status" ;;
            esac
            BASH, [$ancestor, $tip]) === 'yes';
    }

    /** @param list<string> $arguments */
    private function readWorkspace(Task $group, string $script, array $arguments): string
    {
        $group->loadMissing('taskable');
        $instance = $group->taskable;
        if (! $instance instanceof Instance || $instance->checkout_path === '') {
            throw new TaskPullRequestException('The workspace refs could not be read.');
        }
        $instance->loadMissing('node');
        try {
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, ...$arguments],
                input: WorkspaceGit::bashPreamble().$script,
            ), 'task-base-read', 'tasks.fetch_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException('The workspace refs could not be read.', previous: $exception);
        }

        return trim($result->stdout);
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
            || ! is_string($default) || ! GitBranchName::isValid($default)) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.');
        }
        $taskBranch = 'task-'.$group->id;
        if (! GitBranchName::isValid($taskBranch)) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.');
        }

        try {
            $environment = $this->reads->for((string) $group->project->repository_url, $group->project->source_access);
        } catch (ResourceOperationException $exception) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.', previous: $exception);
        }

        $this->fetchRefs($instance, $environment, $default, $taskBranch, $this->pullRequestBase($group, $repository, $default, $taskBranch));
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
            git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" ls-remote --exit-code --heads origin "refs/heads/$task_branch" >/dev/null || status=$?
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
            git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" fetch --no-tags --quiet origin "${refspecs[@]}"
            if [ "$status" -eq 2 ]; then
                git -C "$checkout" update-ref -d "refs/remotes/origin/$task_branch"
            fi
            BASH);
        try {
            $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, $default, $taskBranch, $pullBase ?? ''],
                input: $script->input,
                protectedInput: $script->protectedInput,
            ), 'task-turn-fetch', 'tasks.fetch_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.', previous: $exception);
        }
    }

    private function fetchRef(Instance $instance, string $base, string $token): void
    {
        $instance->loadMissing('node');
        $script = GitReadScript::for(GitReadEnvironment::forGitHubToken($token), <<<'BASH'
            checkout=$1
            base=$2
            git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" fetch --quiet origin "$base"
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
