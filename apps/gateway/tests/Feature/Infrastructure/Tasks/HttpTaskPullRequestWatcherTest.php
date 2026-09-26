<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskPullRequestCheck;
use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use App\Models\App as OrbitApp;
use App\Models\TaskGroup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

function watcher_group(string $url = 'https://github.com/acme/orbit/pull/42'): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'Watcher App', 'slug' => 'watcher-app',
        'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main',
    ]);

    return TaskGroup::query()->create([
        'app_id' => $app->id, 'title' => 'Watch PR', 'brief' => 'Verify PR state',
        'status' => 'settling', 'pr_url' => $url,
    ])->load('app');
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

beforeEach(function (): void {
    Http::preventStrayRequests();
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

it('reports no status without an App, for another repository URL, or when GitHub fails', function (?string $url, bool $app, int $status): void {
    if ($app) {
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

it('reports no health without an App, for another repository URL, or when GitHub fails', function (?string $url, bool $app, int $status): void {
    if ($app) {
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
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/acme/orbit/commits/abc123/check-runs?per_page=100'
        && $request->hasHeader('Authorization', 'Bearer ghs_checks'));
    Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['orbit'], 'permissions' => ['checks' => 'read']]);
    Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['orbit'], 'permissions' => ['contents' => 'write', 'pull_requests' => 'write']]);
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
    $second = $watcher->health(TaskGroup::query()->sole()->load('app'));

    expect($first?->problems)->toBe(['Check Rust agent failed: https://github.com/acme/orbit/runs/1.'])
        ->and($second?->problems)->toBe($first?->problems)
        ->and($checkRunReads())->toBe(1);

    $this->travel(61)->seconds();
    $watcher->health(TaskGroup::query()->sole()->load('app'));

    expect($checkRunReads())->toBe(2);
});
