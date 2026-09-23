<?php

declare(strict_types=1);

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
