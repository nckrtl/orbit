<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskPullRequestCheck;
use App\Domain\Tasks\TaskPullRequestReviewWatcher;
use App\Domain\Tasks\TaskReviewReadStatus;
use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\GitHub\GitHubTestSupport;

function watcher_group(string $url = 'https://github.com/acme/orbit/pull/42'): Task
{
    $project = Project::query()->create([
        'name' => 'Watcher App', 'slug' => 'watcher-app',
        'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);

    return Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Watch PR', 'brief' => 'Verify PR state',
        'status' => 'settling', 'pr_url' => $url,
    ])->load('project');
}

/**
 * @param  array<string, mixed>  $pullRequest
 * @param  list<array<string, mixed>>  $checkRuns
 */
function watcher_fake_health(array $pullRequest, array $checkRuns = [], int $checksTokenStatus = 201, int $checkRunsStatus = 200, int $pullRequestStatus = 200): void
{
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => static fn (Request $request) => match (true) {
            $request->data()['permissions'] !== ['checks' => 'read'] => Http::response(['token' => 'ghs_watch'], 201),
            $checksTokenStatus === 201 => Http::response(['token' => 'ghs_checks'], 201),
            default => Http::response(['message' => 'The permissions requested are not granted to this installation.'], $checksTokenStatus),
        },
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response([
            'merged' => false, 'state' => 'open', 'mergeable' => true, 'mergeable_state' => 'clean',
            'head' => ['sha' => 'abc123'], 'base' => ['ref' => 'main'], ...$pullRequest,
        ], $pullRequestStatus),
        'https://api.github.com/repos/acme/orbit/commits/abc123/check-runs*' => Http::response(['total_count' => count($checkRuns), 'check_runs' => $checkRuns], $checkRunsStatus),
    ]);
}

/** @return array<string, mixed> */
function watcher_review(array $changes = []): array
{
    return array_replace(GitHubTestSupport::review(), [
        'state' => 'CHANGES_REQUESTED',
        'html_url' => 'https://github.com/acme/orbit/pull/42#pullrequestreview-101',
    ], $changes);
}

/**
 * @param  list<array<string, mixed>>  $reviews
 * @param  array<string, mixed>|null  $detail
 * @param  list<array<string, mixed>>  $comments
 */
function watcher_fake_reviews(array $reviews, ?array $detail = null, array $comments = [], ?string $head = null, string $state = 'open', int $status = 200): stdClass
{
    $source = (object) [...compact('reviews', 'detail', 'comments', 'head', 'state', 'status'), 'detailStatus' => 200, 'commentsStatus' => 200];
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_reviews'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => static fn () => Http::response([
            'merged' => false, 'state' => $source->state, 'head' => ['sha' => $source->head ?? GitHubTestSupport::review()['commit_id']],
        ]),
        'https://api.github.com/repos/acme/orbit/pulls/42/reviews?*' => static fn () => Http::response($source->reviews, $source->status),
        'https://api.github.com/repos/acme/orbit/pulls/42/reviews/101' => static fn () => Http::response($source->detail ?? ($source->reviews[0] ?? []), $source->detailStatus),
        'https://api.github.com/repos/acme/orbit/pulls/42/reviews/101/comments?*' => static fn () => Http::response($source->comments, $source->commentsStatus),
    ]);

    return $source;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

describe('trusted review observations', function (): void {
    it('distinguishes absent trust from invalid trust without reading GitHub', function (mixed $trust, TaskReviewReadStatus $expected): void {
        config(['orbit.tasks.github_reviewers' => $trust]);
        $group = watcher_group();

        $observation = app(TaskPullRequestReviewWatcher::class)->reviews($group);

        expect($observation->status)->toBe($expected);
        Http::assertNothingSent();
    })->with([
        'default empty' => [[], TaskReviewReadStatus::Disabled],
        'unrelated repository' => [['other/orbit' => [42]], TaskReviewReadStatus::Disabled],
        'revoked' => [['acme/orbit' => []], TaskReviewReadStatus::Disabled],
        'invalid entry' => [['acme/orbit' => [42, 'admin']], TaskReviewReadStatus::InvalidTrust],
        'unreadable config' => [null, TaskReviewReadStatus::InvalidTrust],
    ]);

    it('distinguishes complete empty reads from remote or malformed failures and never caches errors', function (array $rows, int $status, TaskReviewReadStatus $expected): void {
        GitHubTestSupport::storeApp();
        config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42]]]);
        $source = watcher_fake_reviews($rows, status: $status);
        $watcher = app(TaskPullRequestReviewWatcher::class);
        $group = watcher_group();

        expect($watcher->reviews($group)->status)->toBe($expected);
        $source->reviews = [];
        $source->status = 200;
        expect($watcher->reviews($group)->status)->toBe(TaskReviewReadStatus::Complete);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/reviews?'));
    })->with([
        'healthy empty' => [[], 200, TaskReviewReadStatus::Complete],
        'HTTP failure' => [[], 503, TaskReviewReadStatus::Unreadable],
        'unknown state' => [[watcher_review(['state' => 'UNKNOWN'])], 200, TaskReviewReadStatus::Unreadable],
        'missing submitted time' => [[watcher_review(['submitted_at' => null])], 200, TaskReviewReadStatus::Unreadable],
        'missing decisive head' => [[watcher_review(['commit_id' => null])], 200, TaskReviewReadStatus::Unreadable],
    ]);

    it('keeps an approval informational after comments and a pending review with null head and time', function (): void {
        GitHubTestSupport::storeApp();
        config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42]]]);
        watcher_fake_reviews([
            watcher_review(['state' => 'APPROVED']),
            watcher_review(['id' => 102, 'state' => 'COMMENTED', 'submitted_at' => '2026-09-03T22:13:41Z']),
            watcher_review(['id' => 103, 'state' => 'PENDING', 'submitted_at' => null, 'commit_id' => null]),
        ]);
        $group = watcher_group();

        $result = app(TaskPullRequestReviewWatcher::class)->reviews($group);

        expect($result->status)->toBe(TaskReviewReadStatus::Complete);
        expect(array_column($result->selection?->approvals ?? [], 'id'))->toBe([101]);
        expect($result->selection?->requests)->toBe([]);
        expect($group->fresh()?->status->value)->toBe('settling');
        expect(Task::query()->where('parent_id', $group->id)->count())->toBe(0);
    });

    it('caches bounded lists for no more than sixty seconds but not PR state', function (): void {
        $this->freezeTime();
        GitHubTestSupport::storeApp();
        config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42]]]);
        $source = watcher_fake_reviews([watcher_review()]);
        $group = watcher_group();
        $watcher = app(TaskPullRequestReviewWatcher::class);
        expect($watcher->reviews($group)->selection?->requests)->toHaveCount(1);
        $source->reviews = [];
        $this->travel(59)->seconds();
        expect($watcher->reviews($group)->selection?->requests)->toHaveCount(1);
        $this->travel(1)->seconds();
        expect($watcher->reviews($group)->selection?->requests)->toBe([]);
        expect(Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/reviews?')))->toHaveCount(2);
        expect(Http::recorded(static fn (Request $r): bool => $r->url() === 'https://api.github.com/repos/acme/orbit/pulls/42'))->toHaveCount(3);
    });

    it('round-trips nonempty review lists through the production file store with classes disabled until expiry', function (): void {
        $this->freezeTime();
        $directory = sys_get_temp_dir().'/orbit-review-cache-'.Str::uuid();
        config(['cache.default' => 'file', 'cache.stores.file.path' => $directory, 'cache.serializable_classes' => false]);
        Cache::purge('file');
        try {
            GitHubTestSupport::storeApp();
            config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42]]]);
            $source = watcher_fake_reviews([watcher_review()]);
            $group = watcher_group();
            $watcher = app(TaskPullRequestReviewWatcher::class);
            $first = $watcher->reviews($group);
            expect($first->selection?->requests)->toHaveCount(1);
            $source->reviews = [];
            $this->travel(59)->seconds();
            // Recreate the driver as well: the hit must come from serialized storage, not memory.
            Cache::purge('file');
            $cached = $watcher->reviews($group);
            expect($cached->status)->toBe(TaskReviewReadStatus::Complete);
            expect($cached->reviews)->toEqual($first->reviews);
            expect($cached->selection?->requests)->toHaveCount(1);
            expect(Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/reviews?')))->toHaveCount(1);
            $this->travel(1)->seconds();
            expect($watcher->reviews($group)->selection?->requests)->toBe([]);
            expect(Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/reviews?')))->toHaveCount(2);
        } finally {
            Cache::purge('file');
            File::deleteDirectory($directory);
        }
    });

    it('invalidates cached selection on head or trust changes and makes closed PR requests ineligible', function (string $change): void {
        GitHubTestSupport::storeApp();
        config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42]]]);
        $source = watcher_fake_reviews([watcher_review()]);
        $group = watcher_group();
        $watcher = app(TaskPullRequestReviewWatcher::class);
        expect($watcher->reviews($group)->selection?->requests)->toHaveCount(1);
        if ($change === 'trust') {
            config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [7]]]);
        }
        $source->reviews = [];
        $source->head = $change === 'head' ? str_repeat('a', 40) : null;
        $source->state = $change === 'closed' ? 'closed' : 'open';

        expect($watcher->reviews($group)->selection?->requests)->toBe([]);
    })->with(['head', 'trust', 'closed']);

    it('isolates cached observations by repository and PR number', function (): void {
        GitHubTestSupport::storeApp();
        config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42], 'other/orbit' => [42]]]);
        watcher_fake_reviews([watcher_review()]);
        Http::fake([
            'https://api.github.com/repos/acme/orbit/pulls/43' => Http::response(['merged' => false, 'state' => 'open', 'head' => ['sha' => GitHubTestSupport::review()['commit_id']]]),
            'https://api.github.com/repos/acme/orbit/pulls/43/reviews?*' => Http::response([]),
            'https://api.github.com/repos/other/orbit/installation' => Http::response(['id' => 9]),
            'https://api.github.com/repos/other/orbit/pulls/42' => Http::response(['merged' => false, 'state' => 'open', 'head' => ['sha' => GitHubTestSupport::review()['commit_id']]]),
            'https://api.github.com/repos/other/orbit/pulls/42/reviews?*' => Http::response([]),
        ]);
        $group = watcher_group();
        $watcher = app(TaskPullRequestReviewWatcher::class);
        expect($watcher->reviews($group)->selection?->requests)->toHaveCount(1);
        $group->pr_url = 'https://github.com/acme/orbit/pull/43';
        expect($watcher->reviews($group)->selection?->requests)->toBe([]);
        $group->pr_url = 'https://github.com/other/orbit/pull/42';
        expect($watcher->reviews($group)->status)->toBe(TaskReviewReadStatus::Unreadable);
        $group->project->repository_url = 'https://github.com/other/orbit.git';
        expect($watcher->reviews($group)->selection?->requests)->toBe([]);
        expect(Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/reviews?')))->toHaveCount(3);
    });

    it('revalidates unchanged sources with uncached PR list review and comment reads', function (): void {
        GitHubTestSupport::storeApp();
        config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42]]]);
        $comment = GitHubTestSupport::comment();
        watcher_fake_reviews([watcher_review()], comments: [$comment, array_replace($comment, ['id' => 1])]);
        $group = watcher_group();
        $watcher = app(TaskPullRequestReviewWatcher::class);
        $candidate = $watcher->reviewCandidate($group, 101)->candidate;
        expect($candidate)->not->toBeNull();
        expect(array_column($candidate->comments, 'id'))->toBe([1, $comment['id']]);

        $result = $watcher->revalidateReviewCandidate($group, $candidate);

        expect($result->status)->toBe(TaskReviewReadStatus::Complete);
        foreach (['/pulls/42', '/pulls/42/reviews?per_page=100&page=1', '/pulls/42/reviews/101', '/pulls/42/reviews/101/comments?per_page=100&page=1'] as $path) {
            expect(Http::recorded(static fn (Request $r): bool => $r->url() === 'https://api.github.com/repos/acme/orbit'.$path))->toHaveCount(2);
        }
        Http::assertSent(static fn (Request $r): bool => $r->url() === 'https://api.github.com/app/installations/9/access_tokens'
            && $r->data()['permissions'] === ['pull_requests' => 'read']);
    });

    it('rejects changed candidates and distinguishes read failures during final revalidation', function (string $change, TaskReviewReadStatus $expected): void {
        GitHubTestSupport::storeApp();
        config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42]]]);
        $review = watcher_review();
        $comment = GitHubTestSupport::comment();
        $source = watcher_fake_reviews([$review], comments: [$comment]);
        $group = watcher_group();
        $watcher = app(TaskPullRequestReviewWatcher::class);
        $candidate = $watcher->reviewCandidate($group, 101)->candidate;
        expect($candidate)->not->toBeNull();
        $rows = [$review];
        $detail = $review;
        $comments = [$comment];
        if ($change === 'body') {
            $rows = [$detail = array_replace($review, ['body' => 'Edited finding'])];
        } elseif ($change === 'detail') {
            $detail['body'] = 'Changed between list and detail';
        } elseif ($change === 'comments') {
            $comments[0]['body'] = 'Edited inline finding';
        } elseif ($change === 'new decision') {
            $rows[] = watcher_review(['id' => 102, 'state' => 'DISMISSED']);
        } elseif ($change === 'trust') {
            config(['orbit.tasks.github_reviewers' => ['acme/orbit' => []]]);
        } elseif ($change === 'removed') {
            $rows = [];
        }
        $source->reviews = $rows;
        $source->detail = $detail;
        $source->comments = $comments;
        $source->head = $change === 'head' ? str_repeat('a', 40) : null;
        $source->state = $change === 'closed' ? 'closed' : 'open';
        $source->status = $change === 'failure' ? 503 : 200;
        $source->detailStatus = $change === 'detail failure' ? 503 : 200;
        $source->commentsStatus = $change === 'comments failure' ? 503 : 200;

        expect($watcher->revalidateReviewCandidate($group, $candidate)->status)->toBe($expected);
        expect(Task::query()->where('parent_id', $group->id)->count())->toBe(0);
    })->with([
        'body' => ['body', TaskReviewReadStatus::Changed],
        'detail race' => ['detail', TaskReviewReadStatus::Changed],
        'inline comments' => ['comments', TaskReviewReadStatus::Changed],
        'new decisive record' => ['new decision', TaskReviewReadStatus::Changed],
        'new head' => ['head', TaskReviewReadStatus::Changed],
        'closed PR' => ['closed', TaskReviewReadStatus::Changed],
        'removed review' => ['removed', TaskReviewReadStatus::Changed],
        'trust removed' => ['trust', TaskReviewReadStatus::Disabled],
        'read failure' => ['failure', TaskReviewReadStatus::Unreadable],
        'selected review failure' => ['detail failure', TaskReviewReadStatus::Unreadable],
        'comment failure' => ['comments failure', TaskReviewReadStatus::Unreadable],
    ]);
});

it('reads merged, closed, and open pull request status through the Gateway GitHub App', function (): void {
    GitHubTestSupport::storeApp();
    $group = watcher_group();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push(['merged' => true, 'state' => 'closed'])
            ->push(['merged' => false, 'state' => 'closed'])
            ->push(['merged' => false, 'state' => 'open']),
    ]);
    $watcher = app(HttpTaskPullRequestWatcher::class);

    expect($watcher->status($group))->toBe('merged')
        ->and($watcher->status($group))->toBe('closed')
        ->and($watcher->status($group))->toBe('open');
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/acme/orbit/pulls/42'
        && $request->hasHeader('Authorization', 'Bearer ghs_watch'));
});

it('reports no status without an App, for another repository URL, or when GitHub fails', function (?string $url, bool $project, int $status): void {
    if ($project) {
        GitHubTestSupport::storeApp();
    }
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response([], $status),
    ]);

    expect(app(HttpTaskPullRequestWatcher::class)->status(watcher_group($url ?? 'https://github.com/acme/orbit/pull/42')))->toBeNull();
})->with([
    'no App' => [null, false, 200],
    'another repository' => ['https://github.com/other/orbit/pull/42', true, 200],
    'GitHub failure' => [null, true, 502],
]);

it('reports no health without an App, for another repository URL, or when GitHub fails', function (?string $url, bool $project, int $status): void {
    if ($project) {
        GitHubTestSupport::storeApp();
    }
    watcher_fake_health([], pullRequestStatus: $status);

    expect(app(HttpTaskPullRequestWatcher::class)->health(watcher_group($url ?? 'https://github.com/acme/orbit/pull/42')))->toBeNull();
})->with([
    'no App' => [null, false, 200],
    'another repository' => ['https://github.com/other/orbit/pull/42', true, 200],
    'GitHub failure' => [null, true, 502],
]);

it('reports merged and closed pull requests without reading their checks', function (bool $merged, string $state): void {
    GitHubTestSupport::storeApp();
    watcher_fake_health(['merged' => $merged, 'state' => 'closed', 'mergeable' => false, 'mergeable_state' => 'dirty']);

    $health = app(HttpTaskPullRequestWatcher::class)->health(watcher_group());

    expect($health?->state)->toBe($state)
        ->and($health?->problems)->toBe([]);
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/check-runs'));
})->with([
    'merged' => [true, 'merged'],
    'closed' => [false, 'closed'],
]);

it('reports an open pull request that conflicts with its base branch', function (array $pullRequest): void {
    GitHubTestSupport::storeApp();
    watcher_fake_health($pullRequest);

    $health = app(HttpTaskPullRequestWatcher::class)->health(watcher_group());

    expect($health?->state)->toBe('open')
        ->and($health?->conflicts)->toBeTrue()
        ->and($health?->baseRef)->toBe('main')
        ->and($health?->failedChecks)->toBe([])
        ->and($health?->problems)->toBe(['It conflicts with main; merge main into the task branch and push.'])
        ->and($health?->reason())->toBe('The pull request needs attention: It conflicts with main; merge main into the task branch and push.');
})->with([
    'not mergeable' => [['mergeable' => false, 'mergeable_state' => 'unknown']],
    'dirty' => [['mergeable' => null, 'mergeable_state' => 'dirty']],
]);

it('does not report a conflict while GitHub still computes mergeability', function (): void {
    GitHubTestSupport::storeApp();
    watcher_fake_health(['mergeable' => null, 'mergeable_state' => 'unknown']);

    $health = app(HttpTaskPullRequestWatcher::class)->health(watcher_group());

    expect($health?->state)->toBe('open')
        ->and($health?->conflicts)->toBeFalse()
        ->and($health?->problems)->toBe([]);
});

it('names each failed check run on the head commit with its URL, through a separate checks token', function (): void {
    GitHubTestSupport::storeApp();
    $run = static fn (string $name, ?string $conclusion, ?string $html = null, ?string $details = null): array => [
        'name' => $name, 'status' => $conclusion === null ? 'in_progress' : 'completed', 'conclusion' => $conclusion,
        'html_url' => $html, 'details_url' => $details,
    ];
    watcher_fake_health([], [
        $run('Rust agent', 'failure', 'https://github.com/acme/orbit/runs/1'),
        $run('Gateway', 'timed_out', null, 'https://ci.example.test/gateway'),
        $run('Docs', 'cancelled', 'https://github.com/acme/orbit/runs/3'),
        $run('CLI', 'startup_failure', 'https://github.com/acme/orbit/runs/4'),
        $run('Deploy', 'action_required'),
        $run('SDK', 'success', 'https://github.com/acme/orbit/runs/6'),
        $run('Lint', 'neutral', 'https://github.com/acme/orbit/runs/7'),
        $run('E2E', 'skipped', 'https://github.com/acme/orbit/runs/8'),
        $run('Stale', 'stale', 'https://github.com/acme/orbit/runs/9'),
        $run('Pending', null, 'https://github.com/acme/orbit/runs/10'),
    ]);

    $health = app(HttpTaskPullRequestWatcher::class)->health(watcher_group());

    expect($health?->problems)->toBe([
        'Check Rust agent failed: https://github.com/acme/orbit/runs/1.',
        'Check Gateway failed: https://ci.example.test/gateway.',
        'Check Docs failed: https://github.com/acme/orbit/runs/3.',
        'Check CLI failed: https://github.com/acme/orbit/runs/4.',
        'Check Deploy failed.',
    ])
        ->and(array_map(static fn (TaskPullRequestCheck $check): array => [$check->name, $check->url], $health?->failedChecks ?? []))->toBe([
            ['Rust agent', 'https://github.com/acme/orbit/runs/1'],
            ['Gateway', 'https://ci.example.test/gateway'],
            ['Deploy', null],
        ])
        ->and(array_map(static fn (TaskPullRequestCheck $check): array => [$check->name, $check->url], $health?->infrastructureChecks ?? []))->toBe([
            ['Docs', 'https://github.com/acme/orbit/runs/3'],
            ['CLI', 'https://github.com/acme/orbit/runs/4'],
        ])
        ->and($health?->checksPending)->toBeTrue()
        ->and($health?->headSha)->toBe('abc123');
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/acme/orbit/commits/abc123/check-runs?per_page=100&page=1'
        && $request->hasHeader('Authorization', 'Bearer ghs_checks'));
    Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['orbit'], 'permissions' => ['checks' => 'read']]);
    Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['orbit'], 'permissions' => ['contents' => 'write', 'pull_requests' => 'write', 'workflows' => 'write']]);
    Http::assertNotSent(static fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && array_key_exists('checks', $request->data()['permissions']) && count($request->data()['permissions']) > 1);
});

it('drops the Required checks rollup while another failed check explains the failure', function (): void {
    GitHubTestSupport::storeApp();
    watcher_fake_health([], [
        ['name' => 'Gateway', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/1'],
        ['name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/2'],
    ]);

    $health = app(HttpTaskPullRequestWatcher::class)->health(watcher_group());

    expect($health?->problems)->toBe(['Check Gateway failed: https://github.com/acme/orbit/runs/1.'])
        ->and(array_map(static fn (TaskPullRequestCheck $check): string => $check->name, $health?->failedChecks ?? []))->toBe(['Gateway'])
        ->and($health?->checksPending)->toBeFalse();
});

it('drops the rollup when a cancelled check explains it, and keeps a rollup that fails alone', function (string $other, array $problems, array $failed, array $infrastructure): void {
    GitHubTestSupport::storeApp();
    $runs = [['name' => 'Required checks', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/2']];
    if ($other !== '') {
        array_unshift($runs, ['name' => 'Web', 'status' => 'completed', 'conclusion' => $other, 'html_url' => 'https://github.com/acme/orbit/runs/1']);
    }
    watcher_fake_health([], $runs);

    $health = app(HttpTaskPullRequestWatcher::class)->health(watcher_group());

    expect($health?->problems)->toBe($problems)
        ->and(array_map(static fn (TaskPullRequestCheck $check): string => $check->name, $health?->failedChecks ?? []))->toBe($failed)
        ->and(array_map(static fn (TaskPullRequestCheck $check): string => $check->name, $health?->infrastructureChecks ?? []))->toBe($infrastructure);
})->with([
    'cancelled' => ['cancelled', ['Check Web failed: https://github.com/acme/orbit/runs/1.'], [], ['Web']],
    'startup failure' => ['startup_failure', ['Check Web failed: https://github.com/acme/orbit/runs/1.'], [], ['Web']],
    'alone' => ['', ['Check Required checks failed: https://github.com/acme/orbit/runs/2.'], ['Required checks'], []],
]);

it('reports the head of a merged pull request', function (): void {
    GitHubTestSupport::storeApp();
    watcher_fake_health(['merged' => true, 'state' => 'closed', 'head' => ['sha' => 'fed987']]);

    $health = app(HttpTaskPullRequestWatcher::class)->health(watcher_group());

    expect($health?->state)->toBe('merged')
        ->and($health?->headSha)->toBe('fed987');
});

it('still reports conflicts and no check problems when GitHub refuses the checks token', function (): void {
    GitHubTestSupport::storeApp();
    watcher_fake_health(['mergeable' => false, 'mergeable_state' => 'dirty'], [
        ['name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/1'],
    ], checksTokenStatus: 422);

    $health = app(HttpTaskPullRequestWatcher::class)->health(watcher_group());

    expect($health?->problems)->toBe(['It conflicts with main; merge main into the task branch and push.']);
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/check-runs'));
});

it('reports no health when GitHub fails to list the check runs', function (): void {
    GitHubTestSupport::storeApp();
    watcher_fake_health(['mergeable' => false], checkRunsStatus: 502);

    expect(app(HttpTaskPullRequestWatcher::class)->health(watcher_group()))->toBeNull();
});

it('reads the check runs of one head commit at most once a minute', function (): void {
    GitHubTestSupport::storeApp();
    watcher_fake_health([], [
        ['name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/1'],
    ]);
    $watcher = app(HttpTaskPullRequestWatcher::class);
    $checkRunReads = static fn (): int => count(Http::recorded(
        static fn (Request $request): bool => str_contains($request->url(), '/check-runs'),
    ));

    $first = $watcher->health(watcher_group());
    $second = $watcher->health(Task::topLevel()->sole()->load('project'));

    expect($first?->problems)->toBe(['Check Rust agent failed: https://github.com/acme/orbit/runs/1.'])
        ->and($second?->problems)->toBe($first?->problems)
        ->and($checkRunReads())->toBe(1);

    $this->travel(61)->seconds();
    $watcher->health(Task::topLevel()->sole()->load('project'));

    expect($checkRunReads())->toBe(2);
});

it('keeps a check pending for 60 minutes or less out of the problems and treats a longer one as infrastructure', function (int $ageSeconds, bool $young, array $problems): void {
    GitHubTestSupport::storeApp();
    watcher_fake_health([], [[
        'name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'id' => 11,
        'started_at' => now()->subSeconds($ageSeconds)->toIso8601String(), 'html_url' => 'https://github.com/acme/orbit/runs/11',
    ]]);

    $health = app(HttpTaskPullRequestWatcher::class)->health(watcher_group());

    expect($health?->checksPending)->toBeTrue()
        ->and($health?->checksYoungPending)->toBe($young)
        ->and($health?->problems)->toBe($problems)
        ->and($health?->failedChecks)->toBe([])
        ->and(array_map(static fn (TaskPullRequestCheck $check): string => $check->name, $health?->infrastructureChecks ?? []))->toBe($young ? [] : ['Web']);
})->with([
    'inside 60 minutes' => [59 * 60, true, []],
    'past 60 minutes' => [61 * 60, false, ['Check Web is still pending: https://github.com/acme/orbit/runs/11.']],
]);

it('names a long-pending check without a url', function (): void {
    GitHubTestSupport::storeApp();
    watcher_fake_health([], [[
        'name' => 'Web', 'status' => 'in_progress', 'conclusion' => null,
        'started_at' => now()->subMinutes(61)->toIso8601String(),
    ]]);

    $health = app(HttpTaskPullRequestWatcher::class)->health(watcher_group());

    expect($health?->problems)->toBe(['Check Web is still pending.'])
        ->and($health?->checksYoungPending)->toBeFalse();
});

it('keeps the first read of a pending check with no started_at and does not move it', function (): void {
    GitHubTestSupport::storeApp();
    watcher_fake_health([], [[
        'name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'html_url' => 'https://github.com/acme/orbit/runs/11',
    ]]);
    $watcher = app(HttpTaskPullRequestWatcher::class);
    $group = watcher_group();

    $first = $watcher->health($group);
    expect($first?->checksYoungPending)->toBeTrue()
        ->and($first?->problems)->toBe([])
        ->and($first?->infrastructureChecks)->toBe([]);

    $this->travel(30)->minutes();
    $second = $watcher->health($group->fresh(['project']));
    expect($second?->checksYoungPending)->toBeTrue()
        ->and($second?->problems)->toBe([]);

    $this->travel(31)->minutes();
    $third = $watcher->health($group->fresh(['project']));
    expect($third?->checksYoungPending)->toBeFalse()
        ->and($third?->checksPending)->toBeTrue()
        ->and($third?->problems)->toBe(['Check Web is still pending: https://github.com/acme/orbit/runs/11.'])
        ->and(array_map(static fn (TaskPullRequestCheck $check): string => $check->name, $third?->infrastructureChecks ?? []))->toBe(['Web']);
});

it('starts a new pending clock when the check run id changes and clears one that completed', function (): void {
    GitHubTestSupport::storeApp();
    $pending = static fn (int $id): array => [
        'id' => $id, 'name' => 'Web', 'status' => 'in_progress', 'conclusion' => null, 'html_url' => 'https://github.com/acme/orbit/runs/'.$id,
    ];
    $success = ['id' => 1, 'name' => 'Web', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/acme/orbit/runs/1'];
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response([
            'merged' => false, 'state' => 'open', 'mergeable' => true, 'mergeable_state' => 'clean',
            'head' => ['sha' => 'abc123'], 'base' => ['ref' => 'main'],
        ]),
        'https://api.github.com/repos/acme/orbit/commits/abc123/check-runs*' => Http::sequence()
            ->push(['total_count' => 1, 'check_runs' => [$pending(1)]])
            ->push(['total_count' => 1, 'check_runs' => [$success]])
            ->push(['total_count' => 1, 'check_runs' => [$pending(2)]]),
    ]);
    $watcher = app(HttpTaskPullRequestWatcher::class);
    $group = watcher_group();

    expect($watcher->health($group)?->checksYoungPending)->toBeTrue();

    $this->travel(61)->minutes();
    $completed = $watcher->health($group->fresh(['project']));
    expect($completed?->checksPending)->toBeFalse()
        ->and($completed?->problems)->toBe([])
        ->and($completed?->infrastructureChecks)->toBe([]);

    $this->travel(61)->seconds();
    expect($watcher->health($group->fresh(['project']))?->checksYoungPending)->toBeTrue()
        ->and($watcher->health($group->fresh(['project']))?->problems)->toBe([]);
});
