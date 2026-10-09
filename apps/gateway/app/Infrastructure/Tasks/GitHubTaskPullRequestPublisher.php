<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubOpenedPullRequest;
use App\Domain\GitHub\GitHubPullRequestDraft;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Domain\Tasks\TaskReviewRequestLogins;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * The token reaches the Node only on the SSH process's standard input, as for a read
 * ([ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app)).
 * A failed push names git's error, with the token removed. A missing Workflows permission is named.
 */
final readonly class GitHubTaskPullRequestPublisher implements TaskPullRequestPublisher
{
    /** Last lines of git's stderr. Progress above them is not useful in an assistance reason. */
    private const int PushErrorLines = 12;

    /** End of those lines, in bytes, so one huge line cannot fill the assistance reason. */
    private const int PushErrorBytes = 2000;

    private const string WorkflowRefusal = 'GitHub refused a change under .github/workflows/: grant the Orbit GitHub App the Workflows (read and write) permission, or push the commit yourself.';

    public function __construct(
        private RepositoryPullRequestAccess $access,
        private GitHubApi $github,
        private TaskWorkspaceExecutor $workspaces,
    ) {}

    public function publish(Task $group, string $body, string $commit): string
    {
        [$repository, $instance, $branch] = $this->target($group);
        $base = $group->project->default_branch;
        if (! is_string($base) || ! GitBranchName::isValid($base)) {
            throw new TaskPullRequestException('The Project has no valid default branch.');
        }

        try {
            $token = $this->access->token($repository);
            $this->pushBranch($instance, $branch, $token, $commit);
            $opened = $this->github->openPullRequest($token, $repository, new GitHubPullRequestDraft($branch, $base, $group->title, $body));
            $this->requestReviewers($token, $repository, $opened);

            return $opened->url;
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The pull request could not be opened: '.$exception->getMessage(), previous: $exception);
        }
    }

    private function requestReviewers(#[SensitiveParameter] string $token, GitHubRepository $repository, GitHubOpenedPullRequest $opened): void
    {
        $configured = config('orbit.tasks.review_request_logins');
        $logins = TaskReviewRequestLogins::withoutAuthor(
            is_array($configured) ? array_values(array_filter($configured, is_string(...))) : [],
            $opened->authorLogin,
        );
        if ($logins === []) {
            return;
        }

        $number = $opened->number ?? $repository->pullRequestNumber($opened->url);
        if ($number === null) {
            Log::warning('The task pull request reviewers could not be requested.', [
                'reason' => 'The pull request number could not be resolved.',
                'repository' => $repository->owner.'/'.$repository->name,
                'url' => $opened->url,
            ]);

            return;
        }

        try {
            $this->github->requestPullRequestReviewers($token, $repository, $number, $logins);
        } catch (GitHubApiException $exception) {
            Log::warning('The task pull request reviewers could not be requested.', [
                'reason' => $exception->getMessage(),
                'repository' => $repository->owner.'/'.$repository->name,
                'pull_request' => $number,
                'reviewers' => $logins,
            ]);
        }
    }

    public function push(Task $group, string $commit): void
    {
        [$repository, $instance, $branch] = $this->target($group);

        try {
            $this->pushBranch($instance, $branch, $this->access->token($repository), $commit);
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The task branch could not be pushed: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return array{GitHubRepository, Instance, string}
     *
     * @throws TaskPullRequestException
     */
    private function target(Task $group): array
    {
        $group->loadMissing(['project', 'taskable']);
        $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
        if (! $repository instanceof GitHubRepository) {
            throw new TaskPullRequestException('The Project repository is not on github.com.');
        }
        $instance = $group->taskable;
        if (! $instance instanceof Instance || $instance->checkout_path === ''
            || $instance->project_id !== $group->project_id
            || ($instance->task_sandbox_id !== null && $instance->taskSandbox?->group_id !== $group->id)
            || ($instance->task_sandbox_id === null && ! TaskVmPlacement::allowsWorkspace($group, $instance))) {
            throw new TaskPullRequestException('The task workspace is unavailable.');
        }

        return [$repository, $instance, TaskRemoteBranch::for($group)];
    }

    private function pushBranch(Instance $instance, string $branch, #[SensitiveParameter] string $token, string $commit): void
    {
        if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1) {
            throw new TaskPullRequestException('The approved commit is not a Git SHA.');
        }
        $instance->loadMissing('node');
        $script = GitReadScript::for(GitReadEnvironment::forGitHubToken($token), <<<'BASH'
            checkout=$1
            branch=$2
            commit=$3
            git_read git -c credential.helper= -c http.followRedirects=false -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" push --quiet origin "$commit:refs/heads/$branch"
            BASH);
        try {
            $this->workspaces->execute($instance, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, $branch, $commit],
                input: $script->input,
                protectedInput: $script->protectedInput,
            ), 'task-pull-request-push', 'tasks.push_failed');
        } catch (RuntimeConvergenceException $exception) {
            $this->failedPush($exception, $token);
        }
    }

    /**
     * Git's own message is the only sign of a rejected push. Keep the last lines, without the
     * installation token. GitHub's workflow refusal names the permission the Project needs.
     */
    private function failedPush(RuntimeConvergenceException $exception, #[SensitiveParameter] string $token): never
    {
        $stderr = $exception->result instanceof CommandResult ? $exception->result->stderr : '';
        $message = 'The task branch could not be pushed.';

        if ($this->refusedWorkflow($stderr)) {
            $message .= ' '.self::WorkflowRefusal;
        }

        $tail = $this->stderrTail($stderr, $token);
        if ($tail !== '') {
            $message .= "\n".$tail;
        }

        throw new TaskPullRequestException($message, previous: $exception);
    }

    private function refusedWorkflow(string $stderr): bool
    {
        return str_contains($stderr, 'refusing to allow a GitHub App to create or update workflow ')
            && str_contains($stderr, '.github/workflows/')
            && str_contains($stderr, 'without `workflows` permission');
    }

    private function stderrTail(string $stderr, #[SensitiveParameter] string $token): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $this->redactToken($stderr, $token));
        $text = trim(mb_scrub($text, 'UTF-8'));
        if ($text === '') {
            return '';
        }

        $tail = implode("\n", array_slice(explode("\n", $text), -self::PushErrorLines));
        if (strlen($tail) <= self::PushErrorBytes) {
            return $tail;
        }

        return mb_strcut($tail, -self::PushErrorBytes, null, 'UTF-8');
    }

    private function redactToken(string $text, #[SensitiveParameter] string $token): string
    {
        if ($token === '') {
            return $text;
        }

        $secrets = [
            'x-access-token:'.$token,
            base64_encode('x-access-token:'.$token),
            base64_encode($token),
            $token,
        ];
        usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return str_replace($secrets, '[REDACTED]', $text);
    }
}
