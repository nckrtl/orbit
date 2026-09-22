<?php

declare(strict_types=1);

use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use App\Models\App as OrbitApp;
use App\Models\TaskGroup;
use Illuminate\Http\Client\Request;
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
    Http::fakeSequence()
        ->push(['merged' => true, 'state' => 'closed'])
        ->push(['merged' => false, 'state' => 'closed'])
        ->push(['merged' => false, 'state' => 'open']);
    $watcher = new HttpTaskPullRequestWatcher;

    expect($watcher->status($group))->toBe('merged')
        ->and($watcher->status($group))->toBe('closed')
        ->and($watcher->status($group))->toBe('open');
});

it('verifies repository branches and the approved commit in a pull request', function (): void {
    config()->set('orbit.tasks.github_token', 'token');
    $group = watcher_group();
    $group->id;
    Http::fake(function (Request $request) use ($group) {
        return match (true) {
            str_ends_with($request->url(), '/pulls/42') => Http::response([
                'base' => ['repo' => ['full_name' => 'acme/orbit'], 'ref' => 'main'],
                'head' => ['ref' => 'task-'.$group->id, 'sha' => str_repeat('a', 40)],
            ]),
            str_ends_with($request->url(), '/repos/acme/orbit') => Http::response(['default_branch' => 'main']),
            str_ends_with($request->url(), '/pulls/42/commits') => Http::response([['sha' => str_repeat('a', 40)]]),
            default => Http::response([], 404),
        };
    });

    expect((new HttpTaskPullRequestWatcher)->verifies($group, str_repeat('a', 40)))->toBeTrue();
});
