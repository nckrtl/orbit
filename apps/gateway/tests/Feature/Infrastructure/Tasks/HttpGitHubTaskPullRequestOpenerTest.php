<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Tasks\HttpGitHubTaskPullRequestOpener;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\TaskGroup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function github_pr_group(string $repository = 'git@github.com:nckrtl/orbit.git'): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => $repository,
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'pr-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.140',
        'wireguard_ip' => '10.44.0.140',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-8',
        'checkout_path' => '/srv/orbit/apps/orbit/task-8',
        'branch' => 'task-8',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Open PR',
        'brief' => 'Ship the settle slice.',
        'status' => TaskGroupStatus::Settling,
    ]);
    $group->taskable()->associate($instance);
    $group->save();

    return $group->fresh(['app', 'taskable']) ?? $group;
}

it('opens a GitHub pull request through the HTTP API', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/nckrtl/orbit/pulls' => Http::response([
            'html_url' => 'https://github.com/nckrtl/orbit/pull/543',
        ], 201),
    ]);
    config()->set('orbit.tasks.github_token', 'gh-token');

    $url = app(HttpGitHubTaskPullRequestOpener::class)->open(github_pr_group());

    expect($url)->toBe('https://github.com/nckrtl/orbit/pull/543');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.github.com/repos/nckrtl/orbit/pulls'
            && $request->hasHeader('Authorization', 'Bearer gh-token')
            && $request->data() === [
                'title' => 'Open PR',
                'body' => 'Ship the settle slice.',
                'head' => 'task-8',
                'base' => 'main',
            ];
    });
});

it('returns null when the token is missing or GitHub refuses the open', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/nckrtl/orbit/pulls' => Http::response(['message' => 'denied'], 401),
    ]);
    config()->set('orbit.tasks.github_token', null);
    $group = github_pr_group();

    expect(app(HttpGitHubTaskPullRequestOpener::class)->open($group))->toBeNull();

    Http::assertNothingSent();

    config()->set('orbit.tasks.github_token', 'gh-token');

    expect(app(HttpGitHubTaskPullRequestOpener::class)->open($group))->toBeNull();
});

it('returns null when the App repository is not on github.com', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    config()->set('orbit.tasks.github_token', 'gh-token');

    expect(app(HttpGitHubTaskPullRequestOpener::class)->open(
        github_pr_group('git@example.test:orbit.git'),
    ))->toBeNull();

    Http::assertNothingSent();
});
