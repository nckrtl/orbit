<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Tasks\TaskGitHubReviewObservations;
use App\Domain\Tasks\TaskReviewObservation;
use App\Domain\Tasks\TaskReviewReadStatus;
use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Domain\Tasks\ApprovalObservationFixtures as Fixtures;
use Tests\Feature\GitHub\GitHubTestSupport;

beforeEach(function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
});

describe('durable approval evidence', function (): void {
    it('retains immutable provenance through repeat scans and a new service instance', function (): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()]));
        $source = $records->report($group)['records'][0]['source'];
        $this->travel(10)->seconds();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review(login: 'renamed')]));
        $report = $records->report($group);

        expect($report['records'])->toHaveCount(1)
            ->and($report['records'][0]['source'])->toBe($source)
            ->and($source)->toMatchArray(['reviewer_id' => 42, 'review_id' => 101, 'reviewer_login' => 'source-name', 'commit_id' => 'abc123'])
            ->and($source['review_url'])->toBe(GitHubTestSupport::review()['html_url'])
            ->and($source['submitted_at'])->not->toBeNull()
            ->and($source['first_observed_at'])->not->toBeNull()
            ->and($report['records'][0]['latest'])->toMatchArray(['reviewer_login' => 'renamed', 'selected_review_id' => 101, 'trusted' => true, 'review_state' => 'APPROVED'])
            ->and($report['records'][0]['reported_status'])->toBe('current')
            ->and($group->fresh()->status->value)->toBe('settling');
    });

    it('retains historical evidence for each known invalidation without fallback', function (string $change, string $reason): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()]));
        $observation = match ($change) {
            'head' => Fixtures::observation([Fixtures::review()], head: 'next-head'),
            'dismissal' => Fixtures::observation([Fixtures::review(state: GitHubReviewState::Dismissed)]),
            'supersession' => Fixtures::observation([Fixtures::review(), Fixtures::review(102, GitHubReviewState::ChangesRequested, 'old-head')]),
            'dismissed newer' => Fixtures::observation([Fixtures::review(), Fixtures::review(102, GitHubReviewState::Dismissed)]),
            'trust' => Fixtures::observation([Fixtures::review()], accounts: []),
            'missing' => Fixtures::observation([]),
            'closed' => Fixtures::observation([Fixtures::review()], state: GitHubPullRequestState::Closed),
            'merged' => Fixtures::observation([Fixtures::review()], state: GitHubPullRequestState::Merged),
            default => Fixtures::observation([Fixtures::review()], repository: 'other/orbit'),
        };
        $records->finish($group, $records->begin($group), $observation);
        $record = (new TaskGitHubReviewObservations)->report($group)['records'][0];

        expect($record['reported_status'])->toBe('historical')
            ->and($record['confirmed_reasons'])->toContain($reason)
            ->and($record['source']['reviewer_login'])->toBe('source-name');
    })->with([
        ['head', 'stale_head'], ['dismissal', 'dismissed'], ['supersession', 'superseded'],
        ['dismissed newer', 'superseded'], ['trust', 'trust_removed'], ['missing', 'review_missing'],
        ['closed', 'pull_request_closed'], ['merged', 'pull_request_closed'], ['target', 'target_changed'],
    ]);

    it('does not let commented or pending reviews supersede approval or record untrusted approvals', function (): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([
            Fixtures::review(), Fixtures::review(102, GitHubReviewState::Commented),
            Fixtures::review(103, GitHubReviewState::Pending), Fixtures::review(104, reviewer: 99),
        ]));
        $report = $records->report($group);
        expect($report['records'])->toHaveCount(1)
            ->and($report['records'][0]['reported_status'])->toBe('current')
            ->and($report['records'][0]['latest']['selected_review_id'])->toBe(101);
    });

    it('reports failed or incomplete scans as unverified while retaining confirmed evidence', function (string $head, string $confirmed): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()], head: $head));
        $original = $records->report($group);
        $this->travel(10)->seconds();
        $records->finish($group, $records->begin($group), new TaskReviewObservation(TaskReviewReadStatus::Unreadable));
        $report = $records->report($group);
        expect($report['scan']['read_status'])->toBe('unreadable')
            ->and($report['scan']['last_successful_scan_at'])->toBe($original['scan']['last_successful_scan_at'])
            ->and($report['records'][0]['reported_status'])->toBe('unverified')
            ->and($report['records'][0]['confirmed_status'])->toBe($confirmed)
            ->and($report['records'][0]['confirmed_reasons'])->toBe($original['records'][0]['confirmed_reasons'])
            ->and($report['records'][0]['source'])->toBe($original['records'][0]['source'])
            ->and($report['records'][0]['latest']['last_successful_check_at'])->toBe($original['records'][0]['latest']['last_successful_check_at']);
    })->with([['abc123', 'current'], ['new-head', 'historical']]);

    it('establishes known historical head or trust changes even after an incomplete scan', function (string $change): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()]));
        $observation = $change === 'trust'
            ? Fixtures::observation(accounts: [], read: TaskReviewReadStatus::Disabled)
            : Fixtures::observation(head: 'new-head', read: TaskReviewReadStatus::Unreadable);
        $records->finish($group, $records->begin($group), $observation);
        expect($records->report($group)['records'][0]['reported_status'])->toBe('historical');
    })->with(['head', 'trust']);

    it('expires evidence only after sixty seconds and recovers on a complete scan', function (): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()]));
        $this->travel(60)->seconds();
        expect($records->report($group)['records'][0]['reported_status'])->toBe('current');
        $this->travel(1)->seconds();
        expect($records->report($group)['records'][0]['reported_status'])->toBe('unverified')
            ->and($records->report($group)['records'][0]['confirmed_status'])->toBe('current')
            ->and($records->report($group)['scan']['age_seconds'])->toBe(61);
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()]));
        expect($records->report($group)['records'][0]['reported_status'])->toBe('current');
    });

    it('fences older complete and failed responses atomically and shows interrupted scans as unverified', function (): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $old = $records->begin($group);
        $new = $records->begin($group);
        $records->finish($group, $new, Fixtures::observation([Fixtures::review(102)]));
        $expected = $records->report($group);
        expect($records->finish($group, $new, Fixtures::observation([Fixtures::review()])))->toBeFalse();
        expect($records->finish($group, $old, Fixtures::observation([Fixtures::review()])))->toBeFalse()
            ->and($records->finish($group, $old, new TaskReviewObservation(TaskReviewReadStatus::Unreadable)))->toBeFalse()
            ->and($records->report($group))->toBe($expected)
            ->and(DB::table('task_github_review_observations')->count())->toBe(1);
        $records->begin($group);
        expect($records->report($group)['records'][0]['reported_status'])->toBe('unverified');
    });

    it('ends cache-hit attempts without refreshing confirmed evidence or freshness', function (): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()]));
        $confirmed = $records->report($group);
        $this->travel(10)->seconds();
        $sequence = $records->begin($group);
        expect($records->report($group)['records'][0]['reported_status'])->toBe('unverified');
        expect($records->finishCached($group, $sequence))->toBeTrue();
        $cached = $records->report($group);
        expect($cached['records'])->toBe($confirmed['records'])
            ->and($cached['scan']['last_successful_scan_at'])->toBe($confirmed['scan']['last_successful_scan_at'])
            ->and($cached['scan']['applied_sequence'])->toBe($confirmed['scan']['applied_sequence'])
            ->and($cached['scan']['age_seconds'])->toBe(10);
        expect($records->finishCached($group, $sequence))->toBeFalse()
            ->and($records->finish($group, $sequence, Fixtures::observation([])))->toBeFalse();
        $this->travel(50500)->milliseconds();
        $records->finishCached($group, $records->begin($group));
        expect($records->report($group)['scan']['freshness'])->toBe('unverified')
            ->and($records->report($group)['records'][0]['reported_status'])->toBe('unverified');
    });

    it('does not let cached completions revive failed or interrupted scans or overwrite newer attempts', function (): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()]));
        $old = $records->begin($group);
        $new = $records->begin($group);
        $records->finish($group, $new, new TaskReviewObservation(TaskReviewReadStatus::Unreadable));
        $failed = $records->report($group);
        expect($records->finishCached($group, $old))->toBeFalse()
            ->and($records->report($group))->toBe($failed);
        $records->finishCached($group, $records->begin($group));
        expect($records->report($group)['records'][0]['reported_status'])->toBe('unverified')
            ->and($records->report($group)['records'][0]['confirmed_status'])->toBe('current');
        $records->begin($group);
        $records->finishCached($group, $records->begin($group));
        expect($records->report($group)['scan']['reason'])->toBe('scan_in_progress')
            ->and($records->report($group)['records'][0]['reported_status'])->toBe('unverified');
    });

    it('rejects an older successful response after a newer unreadable response', function (): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $old = $records->begin($group);
        $new = $records->begin($group);
        $records->finish($group, $new, new TaskReviewObservation(TaskReviewReadStatus::Unreadable));
        expect($records->finish($group, $old, Fixtures::observation([Fixtures::review()])))->toBeFalse()
            ->and($records->report($group)['scan']['read_status'])->toBe('unreadable')
            ->and($records->report($group)['records'])->toBe([]);
    });

    it('rolls back all source and status writes if publishing the scan fails', function (): void {
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()]));
        $sequence = $records->begin($group);
        $before = $records->report($group);
        DB::unprepared("CREATE TEMP TRIGGER reject_approval_scan BEFORE UPDATE ON task_github_review_scans BEGIN SELECT RAISE(ABORT, 'fixture scan failure'); END");
        try {
            expect(fn () => $records->finish($group, $sequence, Fixtures::observation([Fixtures::review(102)], head: 'new-head')))
                ->toThrow(QueryException::class);
            expect($records->report($group))->toBe($before)
                ->and(DB::table('task_github_review_observations')->count())->toBe(1);
        } finally {
            DB::unprepared('DROP TRIGGER reject_approval_scan');
        }
    });

    it('keeps group repository PR and review identities separate', function (): void {
        $group = Fixtures::group();
        $other = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        foreach ([$group, $other] as $target) {
            $records->finish($target, $records->begin($target), Fixtures::observation([Fixtures::review()]));
        }
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()], number: 43));
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()], repository: 'other/orbit'));
        expect($records->report($group)['records'])->toHaveCount(3)
            ->and($records->report($other)['records'])->toHaveCount(1);
    });
});

describe('watcher persistence without repair dispatch', function (): void {
    it('reports a pending default uncached read as unverified before it can finish', function (): void {
        GitHubTestSupport::storeApp();
        config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42]]]);
        $group = Fixtures::group();
        $records = new TaskGitHubReviewObservations;
        $records->finish($group, $records->begin($group), Fixtures::observation([Fixtures::review()]));
        $confirmed = $records->report($group);
        $source = GitHubTestSupport::review();
        $source['state'] = 'APPROVED';
        $source['commit_id'] = 'abc123';
        $pending = null;
        Http::fake([
            'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
            'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_read'], 201),
            'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response([
                'state' => 'open', 'merged' => false, 'head' => ['sha' => 'abc123'],
            ]),
            'https://api.github.com/repos/acme/orbit/pulls/42/reviews?*' => function () use ($source, $group, &$pending) {
                // This is the durable state a restart sees if the process stops during list I/O.
                $pending = (new TaskGitHubReviewObservations)->report($group);

                return Http::response([$source]);
            },
        ]);
        app(HttpTaskPullRequestWatcher::class)->reviews($group);
        expect($pending['scan']['sequence'])->toBe(2)
            ->and($pending['scan']['applied_sequence'])->toBe(1)
            ->and($pending['scan']['read_status'])->toBe('unreadable')
            ->and($pending['scan']['freshness'])->toBe('unverified')
            ->and($pending['records'][0]['reported_status'])->toBe('unverified')
            ->and($pending['records'][0]['confirmed_status'])->toBe('current')
            ->and($pending['records'][0]['source'])->toBe($confirmed['records'][0]['source'])
            ->and($pending['records'][0]['latest']['last_successful_check_at'])->toBe($confirmed['records'][0]['latest']['last_successful_check_at']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/reviews?'));
    });

    it('persists complete uncached approvals through health and marks failed pagination unverified', function (): void {
        GitHubTestSupport::storeApp();
        config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42]]]);
        $group = Fixtures::group();
        $source = GitHubTestSupport::review();
        $source['state'] = 'APPROVED';
        $response = (object) ['status' => 200, 'incomplete' => false];
        Http::fake([
            'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
            'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_read'], 201),
            'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response([
                'state' => 'open', 'merged' => false, 'mergeable' => true,
                'head' => ['sha' => $source['commit_id']], 'base' => ['ref' => 'main'],
            ]),
            'https://api.github.com/repos/acme/orbit/pulls/42/reviews?*' => function ($request) use ($source, $response) {
                if ($response->incomplete && str_contains($request->url(), 'page=2')) {
                    return Http::response(['message' => 'incomplete scan'], 403);
                }

                return Http::response([$source], $response->status, $response->incomplete
                    ? ['Link' => '<https://api.github.com/repos/acme/orbit/pulls/42/reviews?per_page=100&page=2>; rel="next"'] : []);
            },
            'https://api.github.com/repos/acme/orbit/commits/*/check-runs*' => Http::response(['check_runs' => []]),
        ]);
        // An uncached default read also persists; a cache hit never refreshes confirmed evidence.
        app(HttpTaskPullRequestWatcher::class)->reviews($group);
        $records = new TaskGitHubReviewObservations;
        $firstCheck = $records->report($group)['records'][0]['latest']['last_successful_check_at'];
        $this->travel(10)->seconds();
        app(HttpTaskPullRequestWatcher::class)->reviews($group);
        expect($records->report($group)['records'][0]['reported_status'])->toBe('current')
            ->and($records->report($group)['records'][0]['latest']['last_successful_check_at'])->toBe($firstCheck)
            ->and($records->report($group)['scan']['age_seconds'])->toBe(10);
        app(HttpTaskPullRequestWatcher::class)->health($group);
        expect($records->report($group)['records'][0]['reported_status'])->toBe('current');
        $response->incomplete = true;
        app(HttpTaskPullRequestWatcher::class)->health($group);
        expect($records->report($group)['records'][0]['reported_status'])->toBe('unverified');
        $response->incomplete = false;
        $response->status = 403;
        app(HttpTaskPullRequestWatcher::class)->health($group);
        expect($records->report($group)['records'][0]['reported_status'])->toBe('unverified')
            ->and($group->fresh()->status->value)->toBe('settling')
            ->and($group->tasks()->count())->toBe(0);
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET' && ! str_ends_with($request->url(), '/access_tokens'));
    });
});
