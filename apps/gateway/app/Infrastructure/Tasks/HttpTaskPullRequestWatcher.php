<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reads the state of the pull request Orbit opened, through the Gateway GitHub App.
 */
final readonly class HttpTaskPullRequestWatcher implements TaskPullRequestWatcher
{
    private const int CHECKS_CACHE_SECONDS = 60;

    public function __construct(private RepositoryPullRequestAccess $access, private GitHubApi $github) {}

    public function status(TaskGroup $group): ?string
    {
        $target = $this->target($group);
        if ($target === null) {
            return null;
        }
        [$repository, $number] = $target;
        try {
            return $this->github->pullRequest($this->access->token($repository), $repository, $number)->state->value;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Check runs need the separate `checks: read` token. Without it, only conflicts are reported.
     */
    public function health(TaskGroup $group): ?TaskPullRequestHealth
    {
        $target = $this->target($group);
        if ($target === null) {
            return null;
        }
        [$repository, $number] = $target;
        try {
            $pullRequest = $this->github->pullRequest($this->access->token($repository), $repository, $number);
            if ($pullRequest->state !== GitHubPullRequestState::Open) {
                return new TaskPullRequestHealth($pullRequest->state->value);
            }
            $failed = $this->failedChecks($repository, $number, $pullRequest);
        } catch (Throwable) {
            return null;
        }

        $problems = [];
        if ($pullRequest->conflicts()) {
            $base = $pullRequest->baseRef ?? 'the base branch';
            $problems[] = 'It conflicts with '.$base.'; merge '.$base.' into the task branch and push.';
        }
        foreach ($failed as $run) {
            $problems[] = 'Check '.$run->name.' failed'.($run->url !== null ? ': '.$run->url : '').'.';
        }

        return new TaskPullRequestHealth('open', $problems);
    }

    /**
     * Failed check runs on the head commit, read at most once a minute per commit. The scheduler ticks
     * every few seconds, and each read costs a checks token and a check-run list against the App's rate
     * limit. Only names and URLs are cached, never a token. A new push has a new head, so it reads fresh.
     *
     * @return list<GitHubCheckRun>
     */
    private function failedChecks(GitHubRepository $repository, int $number, GitHubPullRequest $pullRequest): array
    {
        if ($pullRequest->headSha === null) {
            return [];
        }
        $key = 'tasks:pull-request-checks:'.$repository->owner.'/'.$repository->name.'#'.$number.'@'.$pullRequest->headSha;
        /** @var list<array{name: string, url: ?string}>|null $failed */
        $failed = Cache::get($key);
        if (! is_array($failed)) {
            $token = $this->access->checksToken($repository);
            if ($token === null) {
                // Not cached: a missing permission or a passing token failure is re-read next tick.
                return [];
            }
            $failed = array_values(array_map(
                static fn (GitHubCheckRun $run): array => ['name' => $run->name, 'url' => $run->url],
                array_filter(
                    $this->github->checkRuns($token, $repository, $pullRequest->headSha),
                    static fn (GitHubCheckRun $run): bool => $run->failed(),
                ),
            ));
            Cache::put($key, $failed, self::CHECKS_CACHE_SECONDS);
        }

        return array_map(
            static fn (array $run): GitHubCheckRun => new GitHubCheckRun($run['name'], 'failure', $run['url']),
            $failed,
        );
    }

    /** @return array{GitHubRepository, int}|null */
    private function target(TaskGroup $group): ?array
    {
        $repository = GitHubRepository::fromOrigin((string) $group->app->repository_url);
        if (! $repository instanceof GitHubRepository || ! is_string($group->pr_url)) {
            return null;
        }
        $number = $repository->pullRequestNumber($group->pr_url);

        return $number === null ? null : [$repository, $number];
    }
}
