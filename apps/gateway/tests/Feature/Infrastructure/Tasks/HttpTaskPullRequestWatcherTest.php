<?php

declare(strict_types=1);

use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use App\Models\App as OrbitApp;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\Http;

function watcher_group(): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'Watcher App', 'slug' => 'watcher-app',
        'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main',
    ]);

    return TaskGroup::query()->create([
        'app_id' => $app->id, 'title' => 'Watch PR', 'brief' => 'Verify PR state',
        'status' => 'settling', 'pr_url' => 'https://github.com/acme/orbit/pull/42',
    ])->load('app');
}

it('reads merged, closed, and open pull request status', function (): void {
    config()->set('orbit.tasks.github_token', 'token');
    $group = watcher_group();
    Http::preventStrayRequests();
    Http::fakeSequence('https://api.github.com/repos/acme/orbit/pulls/42')
        ->push(['merged' => true, 'state' => 'closed'])
        ->push(['merged' => false, 'state' => 'closed'])
        ->push(['merged' => false, 'state' => 'open']);
    $watcher = new HttpTaskPullRequestWatcher;

    expect($watcher->status($group))->toBe('merged')
        ->and($watcher->status($group))->toBe('closed')
        ->and($watcher->status($group))->toBe('open');
});

/** @return array<string, mixed> */
function watcher_pull_request(TaskGroup $group): array
{
    return [
        'number' => 42,
        'html_url' => 'https://github.com/acme/orbit/pull/42',
        'base' => ['repo' => ['full_name' => 'acme/orbit'], 'ref' => 'main'],
        'head' => ['repo' => ['full_name' => 'acme/orbit'], 'ref' => 'task-'.$group->id, 'sha' => str_repeat('a', 40)],
    ];
}

it('verifies the candidate URL before the group has a pull request', function (): void {
    config()->set('orbit.tasks.github_token', 'token');
    $group = watcher_group();
    $group->update(['pr_url' => null]);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(watcher_pull_request($group)),
        'https://api.github.com/repos/acme/orbit' => Http::response(['default_branch' => 'main']),
    ]);

    expect((new HttpTaskPullRequestWatcher)->verifies($group, 'https://github.com/acme/orbit/pull/42', str_repeat('a', 40)))->toBeTrue();
    expect($group->fresh()->pr_url)->toBeNull();
    Http::assertSentCount(2);
});

it('rejects a candidate outside the exact GitHub pull request identity without making requests', function (string $url): void {
    config()->set('orbit.tasks.github_token', 'token');
    $group = watcher_group();
    Http::preventStrayRequests();

    expect((new HttpTaskPullRequestWatcher)->verifies($group, $url, str_repeat('a', 40)))->toBeFalse();
    $group->pr_url = $url;
    expect((new HttpTaskPullRequestWatcher)->status($group))->toBeNull();
    Http::assertNothingSent();
})->with([
    'wrong host' => 'https://evil.example/acme/orbit/pull/42',
    'host suffix' => 'https://github.com.evil.example/acme/orbit/pull/42',
    'wrong owner' => 'https://github.com/other/orbit/pull/42',
    'wrong repository' => 'https://github.com/acme/other/pull/42',
    'insecure scheme' => 'http://github.com/acme/orbit/pull/42',
    'userinfo' => 'https://attacker@github.com/acme/orbit/pull/42',
    'port' => 'https://github.com:443/acme/orbit/pull/42',
    'query' => 'https://github.com/acme/orbit/pull/42?another=1',
    'fragment' => 'https://github.com/acme/orbit/pull/42#review',
    'extra path' => 'https://github.com/acme/orbit/pull/42/files',
    'invalid number' => 'https://github.com/acme/orbit/pull/0',
    'not a URL' => 'pull/42',
]);

it('rejects GitHub artifacts that do not match the approved pull request', function (string $field, mixed $value): void {
    config()->set('orbit.tasks.github_token', 'token');
    $group = watcher_group();
    $artifacts = [
        'pr' => watcher_pull_request($group),
        'repo' => ['default_branch' => 'main'],
    ];
    data_set($artifacts, $field, $value);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response($artifacts['pr']),
        'https://api.github.com/repos/acme/orbit' => Http::response($artifacts['repo']),
    ]);

    expect((new HttpTaskPullRequestWatcher)->verifies($group, 'https://github.com/acme/orbit/pull/42', str_repeat('a', 40)))->toBeFalse();
})->with([
    'wrong PR number' => ['pr.number', 43],
    'wrong PR URL' => ['pr.html_url', 'https://github.com/acme/orbit/pull/43'],
    'wrong base repository' => ['pr.base.repo.full_name', 'other/orbit'],
    'fork with the same branch' => ['pr.head.repo.full_name', 'other/orbit'],
    'wrong head branch' => ['pr.head.ref', 'another-task'],
    'wrong target branch' => ['pr.base.ref', 'release'],
    'unknown target branch' => ['repo.default_branch', null],
    'empty target branch' => ['repo.default_branch', ''],
    'missing head commit' => ['pr.head.sha', null],
]);

it('rejects a later unreviewed head even when the approved commit is in the pull request', function (): void {
    config()->set('orbit.tasks.github_token', 'token');
    $group = watcher_group();
    $pr = watcher_pull_request($group);
    data_set($pr, 'head.sha', str_repeat('b', 40));
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response($pr),
        'https://api.github.com/repos/acme/orbit' => Http::response(['default_branch' => 'main']),
        'https://api.github.com/repos/acme/orbit/pulls/42/commits' => Http::response([
            ['sha' => str_repeat('a', 40)], ['sha' => str_repeat('b', 40)],
        ]),
    ]);

    expect((new HttpTaskPullRequestWatcher)->verifies($group, 'https://github.com/acme/orbit/pull/42', str_repeat('a', 40)))->toBeFalse();
    Http::assertSentCount(2);
});

it('requires credentials before verification or merge watching', function (): void {
    config()->set('orbit.tasks.github_token', null);
    $group = watcher_group();
    Http::preventStrayRequests();

    expect((new HttpTaskPullRequestWatcher)->verifies($group, 'https://github.com/acme/orbit/pull/42', str_repeat('a', 40)))->toBeFalse();
    expect((new HttpTaskPullRequestWatcher)->status($group))->toBeNull();
    Http::assertNothingSent();
});

it('does not verify a pull request when any GitHub read fails', function (string $endpoint, bool $connectionFailure): void {
    config()->set('orbit.tasks.github_token', 'token');
    $group = watcher_group();
    $responses = [
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(watcher_pull_request($group)),
        'https://api.github.com/repos/acme/orbit' => Http::response(['default_branch' => 'main']),
    ];
    $responses['https://api.github.com/repos/acme/orbit'.$endpoint] = $connectionFailure ? Http::failedConnection() : Http::response([], 503);
    Http::preventStrayRequests();
    Http::fake($responses);

    expect((new HttpTaskPullRequestWatcher)->verifies($group, 'https://github.com/acme/orbit/pull/42', str_repeat('a', 40)))->toBeFalse();
})->with(['pull request' => '/pulls/42', 'repository' => ''])->with(['HTTP failure' => false, 'network failure' => true]);
