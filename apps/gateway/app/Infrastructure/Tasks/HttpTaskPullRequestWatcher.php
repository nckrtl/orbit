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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reads the state of the pull request Orbit opened, through the Gateway GitHub App.
 */
final readonly class HttpTaskPullRequestWatcher implements TaskPullRequestWatcher
{
    private const int CHECKS_CACHE_SECONDS = 60;

    /** A run pending for longer than this is infrastructure. One pending for this long or less is not a result yet. */
    private const int PENDING_YOUNG_MINUTES = 60;

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
     * A rollup check is dropped while another failed check explains the failure. Cancelled runs, runs
     * that could not start, and runs pending for more than 60 minutes are infrastructure, kept apart
     * from genuine failures (ADR 0164).
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
        $young = false;
        $stale = [];
        if ($pullRequest->headSha !== null) {
            $this->rotatePendingHead($repository, $number, $pullRequest->headSha);
            ['young' => $young, 'stale' => $stale] = $this->classifyPending(
                $this->pendingHeadKey($repository, $number, $pullRequest->headSha),
                $pending,
            );
        }
        $problems = [];
        if ($pullRequest->conflicts()) {
            $base = $pullRequest->baseRef ?? 'the base branch';
            $problems[] = 'It conflicts with '.$base.'; merge '.$base.' into the task branch and push.';
        }
        foreach ($failed as $run) {
            $problems[] = 'Check '.$run->name.' failed'.($run->url !== null ? ': '.$run->url : '').'.';
        }
        foreach ($stale as $run) {
            $problems[] = 'Check '.$run->name.' is still pending'.($run->url !== null ? ': '.$run->url : '').'.';
        }
        $check = static fn (GitHubCheckRun $run): TaskPullRequestCheck => new TaskPullRequestCheck($run->name, $run->url);
        $infrastructure = array_values(array_map($check, array_filter($failed, static fn (GitHubCheckRun $run): bool => $run->infrastructure())));
        foreach ($stale as $run) {
            $infrastructure[] = $check($run);
        }

        return new TaskPullRequestHealth(
            state: 'open',
            problems: $problems,
            baseRef: $pullRequest->baseRef,
            conflicts: $pullRequest->conflicts(),
            failedChecks: array_values(array_map($check, array_filter($failed, static fn (GitHubCheckRun $run): bool => ! $run->infrastructure()))),
            headSha: $pullRequest->headSha,
            infrastructureChecks: $infrastructure,
            checksPending: $pending !== [],
            checksYoungPending: $young,
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
     * Failed and not-yet-completed check runs on the head commit, read at most once a minute per commit.
     * The scheduler ticks every few seconds, and each read costs a checks token and a check-run list
     * against the App's rate limit. Only names, conclusions, ids, start times, and URLs are cached, never
     * a token. A new push has a new head, so it reads fresh. How long a run with no `started_at` has been
     * pending is stored apart from this cache, so a later read does not move that time.
     *
     * @return array{failed: list<GitHubCheckRun>, pending: list<GitHubCheckRun>}
     */
    private function headChecks(GitHubRepository $repository, int $number, GitHubPullRequest $pullRequest): array
    {
        if ($pullRequest->headSha === null) {
            return ['failed' => [], 'pending' => []];
        }
        $key = 'tasks:pull-request-checks:v3:'.$repository->owner.'/'.$repository->name.'#'.$number.'@'.$pullRequest->headSha;
        /** @var array{failed: list<array{name: string, conclusion: string, url: ?string}>, pending: list<array{id: ?int, name: string, url: ?string, started_at: ?string}>}|null $cached */
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            $token = $this->access->checksToken($repository);
            if ($token === null) {
                // Not cached: a missing permission or a passing token failure is re-read next tick.
                return ['failed' => [], 'pending' => []];
            }
            $runs = $this->github->checkRuns($token, $repository, $pullRequest->headSha);
            $cached = [
                'failed' => array_values(array_map(
                    static fn (GitHubCheckRun $run): array => ['name' => $run->name, 'conclusion' => (string) $run->conclusion, 'url' => $run->url],
                    array_filter($runs, static fn (GitHubCheckRun $run): bool => $run->failed()),
                )),
                'pending' => array_values(array_map(
                    static fn (GitHubCheckRun $run): array => ['id' => $run->id, 'name' => $run->name, 'url' => $run->url, 'started_at' => $run->startedAt],
                    array_filter($runs, static fn (GitHubCheckRun $run): bool => $run->pending()),
                )),
            ];
            Cache::put($key, $cached, self::CHECKS_CACHE_SECONDS);
        }

        return [
            'failed' => array_map(
                static fn (array $run): GitHubCheckRun => new GitHubCheckRun($run['name'], $run['conclusion'], $run['url']),
                $cached['failed'],
            ),
            'pending' => array_map(
                static fn (array $run): GitHubCheckRun => new GitHubCheckRun($run['name'], null, $run['url'], $run['id'], $run['started_at']),
                $cached['pending'],
            ),
        ];
    }

    /**
     * A run with no conclusion is pending. `started_at` ages it from GitHub's clock. Without that time,
     * the first read on this head starts the clock and a later read leaves it. More than 60 minutes is
     * infrastructure. The clock is dropped when the run completes or the head changes.
     *
     * @param  list<GitHubCheckRun>  $pending
     * @return array{young: bool, stale: list<GitHubCheckRun>}
     */
    private function classifyPending(string $headKey, array $pending): array
    {
        $young = false;
        $stale = [];
        $current = [];
        foreach ($pending as $run) {
            $runKey = $run->id !== null ? (string) $run->id : $run->name;
            $current[$runKey] = true;
            $started = $run->startedAt !== null ? $this->parsedStart($run->startedAt) : null;
            if (! $started instanceof Carbon) {
                $started = $this->pendingSince($headKey, $runKey);
            }
            if ($started->lt(now()->subMinutes(self::PENDING_YOUNG_MINUTES))) {
                $stale[] = $run;
            } else {
                $young = true;
            }
        }
        $this->forgetFinishedPending($headKey, $current);

        return ['young' => $young, 'stale' => $stale];
    }

    private function parsedStart(string $startedAt): ?Carbon
    {
        try {
            return Carbon::parse($startedAt);
        } catch (Throwable) {
            return null;
        }
    }

    /** The first tick that saw this run unfinished on the head. A later read returns the same instant. */
    private function pendingSince(string $headKey, string $runKey): Carbon
    {
        $key = $this->pendingClockKey($headKey, $runKey);
        $stored = Cache::get($key);
        if (! is_string($stored) || $stored === '') {
            $stored = now()->toIso8601String();
            Cache::forever($key, $stored);
            $index = Cache::get($this->pendingIndexKey($headKey));
            $index = is_array($index) ? $index : [];
            $index[$runKey] = true;
            Cache::forever($this->pendingIndexKey($headKey), $index);
        }

        return Carbon::parse($stored);
    }

    /** @param  array<string, true>  $current */
    private function forgetFinishedPending(string $headKey, array $current): void
    {
        $stored = Cache::get($this->pendingIndexKey($headKey));
        if (! is_array($stored)) {
            return;
        }
        $remaining = [];
        foreach (array_keys($stored) as $runKey) {
            $runKey = (string) $runKey;
            if (isset($current[$runKey])) {
                $remaining[$runKey] = true;

                continue;
            }
            Cache::forget($this->pendingClockKey($headKey, $runKey));
        }
        if ($remaining === []) {
            Cache::forget($this->pendingIndexKey($headKey));

            return;
        }
        Cache::forever($this->pendingIndexKey($headKey), $remaining);
    }

    /** Drops clocks for the previous head, so a new push starts pending ages again. */
    private function rotatePendingHead(GitHubRepository $repository, int $number, string $headSha): void
    {
        $pointer = 'tasks:check-pending-head:v1:'.$repository->owner.'/'.$repository->name.'#'.$number;
        $previous = Cache::get($pointer);
        if (is_string($previous) && $previous !== '' && $previous !== $headSha) {
            $this->forgetFinishedPending($this->pendingHeadKey($repository, $number, $previous), []);
        }
        if ($previous !== $headSha) {
            Cache::forever($pointer, $headSha);
        }
    }

    private function pendingHeadKey(GitHubRepository $repository, int $number, string $headSha): string
    {
        return $repository->owner.'/'.$repository->name.'#'.$number.'@'.$headSha;
    }

    private function pendingClockKey(string $headKey, string $runKey): string
    {
        return 'tasks:check-pending-since:v1:'.$headKey.':'.$runKey;
    }

    private function pendingIndexKey(string $headKey): string
    {
        return 'tasks:check-pending-index:v1:'.$headKey;
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
