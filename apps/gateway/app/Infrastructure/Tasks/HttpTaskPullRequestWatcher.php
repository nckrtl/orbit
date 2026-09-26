<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Tasks\TaskPullRequestCheck;
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
     * A rollup check is dropped while another failed check explains the failure. Cancelled runs and runs
     * that could not start are infrastructure, kept apart from genuine failures (ADR 0164).
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
                return new TaskPullRequestHealth($pullRequest->state->value, headSha: $pullRequest->headSha);
            }
            ['failed' => $failed, 'pending' => $pending] = $this->headChecks($repository, $number, $pullRequest);
        } catch (Throwable) {
            return null;
        }

        $failed = self::withoutExplainedRollups($failed);
        $problems = [];
        if ($pullRequest->conflicts()) {
            $base = $pullRequest->baseRef ?? 'the base branch';
            $problems[] = 'It conflicts with '.$base.'; merge '.$base.' into the task branch and push.';
        }
        foreach ($failed as $run) {
            $problems[] = 'Check '.$run->name.' failed'.($run->url !== null ? ': '.$run->url : '').'.';
        }
        $check = static fn (GitHubCheckRun $run): TaskPullRequestCheck => new TaskPullRequestCheck($run->name, $run->url);

        return new TaskPullRequestHealth(
            state: 'open',
            problems: $problems,
            baseRef: $pullRequest->baseRef,
            conflicts: $pullRequest->conflicts(),
            failedChecks: array_values(array_map($check, array_filter($failed, static fn (GitHubCheckRun $run): bool => ! $run->infrastructure()))),
            headSha: $pullRequest->headSha,
            infrastructureChecks: array_values(array_map($check, array_filter($failed, static fn (GitHubCheckRun $run): bool => $run->infrastructure()))),
            checksPending: $pending,
        );
    }

    /**
     * Drops rollup checks, such as `Required checks`, when another failed run explains the failure.
     * A rollup that fails alone stays.
     *
     * @param  list<GitHubCheckRun>  $failed
     * @return list<GitHubCheckRun>
     */
    private static function withoutExplainedRollups(array $failed): array
    {
        $rollup = static fn (GitHubCheckRun $run): bool => new TaskPullRequestCheck($run->name, $run->url)->rollup();
        $explained = array_filter($failed, static fn (GitHubCheckRun $run): bool => ! $rollup($run)) !== [];

        return $explained ? array_values(array_filter($failed, static fn (GitHubCheckRun $run): bool => ! $rollup($run))) : $failed;
    }

    /**
     * Failed check runs on the head commit, and whether any run is still pending, read at most once a
     * minute per commit. The scheduler ticks every few seconds, and each read costs a checks token and a
     * check-run list against the App's rate limit. Only names, conclusions and URLs are cached, never a
     * token. A new push has a new head, so it reads fresh.
     *
     * @return array{failed: list<GitHubCheckRun>, pending: bool}
     */
    private function headChecks(GitHubRepository $repository, int $number, GitHubPullRequest $pullRequest): array
    {
        if ($pullRequest->headSha === null) {
            return ['failed' => [], 'pending' => false];
        }
        $key = 'tasks:pull-request-checks:v2:'.$repository->owner.'/'.$repository->name.'#'.$number.'@'.$pullRequest->headSha;
        /** @var array{failed: list<array{name: string, conclusion: string, url: ?string}>, pending: bool}|null $cached */
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            $token = $this->access->checksToken($repository);
            if ($token === null) {
                // Not cached: a missing permission or a passing token failure is re-read next tick.
                return ['failed' => [], 'pending' => false];
            }
            $runs = $this->github->checkRuns($token, $repository, $pullRequest->headSha);
            $cached = [
                'failed' => array_values(array_map(
                    static fn (GitHubCheckRun $run): array => ['name' => $run->name, 'conclusion' => (string) $run->conclusion, 'url' => $run->url],
                    array_filter($runs, static fn (GitHubCheckRun $run): bool => $run->failed()),
                )),
                'pending' => array_filter($runs, static fn (GitHubCheckRun $run): bool => $run->pending()) !== [],
            ];
            Cache::put($key, $cached, self::CHECKS_CACHE_SECONDS);
        }

        return [
            'failed' => array_map(
                static fn (array $run): GitHubCheckRun => new GitHubCheckRun($run['name'], $run['conclusion'], $run['url']),
                $cached['failed'],
            ),
            'pending' => $cached['pending'],
        ];
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
