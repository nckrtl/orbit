<?php

declare(strict_types=1);

use App\Actions\Tasks\WatchTaskBranchPullRequestAction;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskScheduler;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

function branch_watch_group(string $status = 'running', string $subtaskStatus = 'todo', ?Project $project = null): Task
{
    $project ??= Project::query()->create([
        'name' => 'Branch watch', 'slug' => 'branch-watch',
        'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Watch branch', 'brief' => 'Find external pull requests.',
        'status' => $status, 'pr_url' => 'https://github.com/acme/orbit/pull/99',
        'assistance_requested' => true, 'assistance_reason' => 'A product question.',
    ]);
    Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Work', 'brief' => 'Keep working.', 'status' => $subtaskStatus,
    ]);

    return $group;
}

/** @return array{number: int, html_url: string, state: string, merged_at: ?string} */
function branch_watch_row(int $number, string $state = 'open', ?string $mergedAt = null): array
{
    return ['number' => $number, 'html_url' => 'https://github.com/acme/orbit/pull/'.$number, 'state' => $state, 'merged_at' => $mergedAt];
}

it('records an externally opened watched pull request for a running group and throttles reads to once a minute', function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create([
        'name' => 'Branch watch', 'slug' => 'branch-watch',
        'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Watch branch', 'brief' => 'Find external pull requests.',
        'status' => 'running', 'assistance_requested' => true, 'assistance_reason' => 'A product question.',
    ]);
    Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Open work', 'brief' => 'Keep working.', 'status' => 'running',
    ]);
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls?*' => Http::sequence()
            ->push([['number' => 42, 'html_url' => 'https://github.com/acme/orbit/pull/42', 'state' => 'open', 'merged_at' => null]])
            ->push([['number' => 42, 'html_url' => 'https://github.com/acme/orbit/pull/42', 'state' => 'closed', 'merged_at' => '2026-10-08T10:00:00Z']]),
    ]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh())->watched_pr_url->toBe('https://github.com/acme/orbit/pull/42')
        ->watched_pr_number->toBe(42)->watched_pr_state->toBe('open')->pr_url->toBeNull()
        ->assistance_reason->toBe('A product question.');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/pulls?') && $request['head'] === 'acme:task-'.$group->id
        && $request['state'] === 'all' && $request->hasHeader('Authorization', 'Bearer ghs_watch'));
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request['repositories'] === ['orbit'] && $request['permissions'] === ['pull_requests' => 'read']);
    Http::assertSentCount(3);

    $this->travel(59)->seconds();
    app(TaskScheduler::class)->tick();
    Http::assertSentCount(3);
    $this->travel(1)->seconds();
    app(TaskScheduler::class)->tick();

    $task = $group->tasks()->firstOrFail();
    expect($group->fresh())->watched_pr_state->toBe('merged')->pr_url->toBeNull()
        ->status->value->toBe('running')->assistance_requested->toBeTrue()
        ->assistance_reason->toBe('Watched pull request ended: https://github.com/acme/orbit/pull/42 is merged. Open subtasks: #'.$task->id.' Open work.');
    Http::assertSentCount(5);
});

it('selects the first open watched pull request or the first ended one on the page', function (array $rows, int $number, string $state, string $subtaskStatus): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    $group = branch_watch_group(subtaskStatus: $subtaskStatus);
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls?*' => Http::response($rows),
    ]);

    app(WatchTaskBranchPullRequestAction::class)->execute($group);

    expect($group->fresh())->watched_pr_number->toBe($number)->watched_pr_state->toBe($state)
        ->watched_pr_url->toBe('https://github.com/acme/orbit/pull/'.$number)
        ->pr_url->toBe('https://github.com/acme/orbit/pull/99')->assistance_reason->toBe('A product question.');
    Http::assertSentCount(3);
})->with([
    'first open, with todo work' => [[branch_watch_row(43, 'closed'), branch_watch_row(42), branch_watch_row(41)], 42, 'open', 'todo'],
    'first closed, with reviewing work' => [[branch_watch_row(43, 'closed'), branch_watch_row(42, 'closed', '2026-10-08T10:00:00Z')], 43, 'closed', 'reviewing'],
    'first merged, with running work' => [[branch_watch_row(43, 'closed', '2026-10-08T10:00:00Z'), branch_watch_row(42, 'closed')], 43, 'merged', 'running'],
]);

it('accepts canonical repository casing for a watched pull request but rejects another repository without changing pr_url', function (string $url, bool $accepted): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    $group = branch_watch_group();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls?*' => Http::response([
            ['number' => 42, 'html_url' => $url, 'state' => 'open', 'merged_at' => null],
        ]),
    ]);

    app(WatchTaskBranchPullRequestAction::class)->execute($group);

    expect($group->fresh())->watched_pr_url->toBe($accepted ? $url : null)
        ->watched_pr_number->toBe($accepted ? 42 : null)->watched_pr_state->toBe($accepted ? 'open' : null)
        ->pr_url->toBe('https://github.com/acme/orbit/pull/99');
    Http::assertSentCount(3);
})->with([
    'canonical casing' => ['https://github.com/Acme/Orbit/pull/42', true],
    'another owner' => ['https://github.com/Other/Orbit/pull/42', false],
    'another repository' => ['https://github.com/Acme/Other/pull/42', false],
]);

it('leaves the watched pull request and assistance unchanged for an empty or unreadable list and throttles failures', function (array $rows, int $status): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    $group = branch_watch_group();
    $group->update(['watched_pr_url' => 'https://github.com/acme/orbit/pull/40', 'watched_pr_number' => 40, 'watched_pr_state' => 'open']);
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls?*' => Http::response($rows, $status),
    ]);

    app(WatchTaskBranchPullRequestAction::class)->execute($group);
    $this->travel(59)->seconds();
    app(WatchTaskBranchPullRequestAction::class)->execute($group);

    expect($group->fresh())->watched_pr_url->toBe('https://github.com/acme/orbit/pull/40')
        ->watched_pr_number->toBe(40)->watched_pr_state->toBe('open')
        ->assistance_requested->toBeTrue()->assistance_reason->toBe('A product question.');
    Http::assertSentCount(3);
})->with([
    'empty page' => [[], 200],
    'server failure' => [[], 502],
    'wrong repository' => [[['number' => 42, 'html_url' => 'https://github.com/other/orbit/pull/42', 'state' => 'open']], 200],
    'invalid state' => [[['number' => 42, 'html_url' => 'https://github.com/acme/orbit/pull/42', 'state' => 'unknown']], 200],
]);

it('refreshes a refused installation token once for the watched pull request and reuses the new id across groups', function (int $retryStatus, int $laterRequests): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    $group = branch_watch_group();
    $other = branch_watch_group(project: $group->project);
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::sequence()->push(['id' => 9])->push(['id' => 10])->push(['id' => 10]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['message' => 'Installation removed.'], 404),
        'https://api.github.com/app/installations/10/access_tokens' => Http::sequence()
            ->push(['token' => 'ghs_watch', 'message' => 'Refused.'], $retryStatus)->push(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls?*' => Http::response([branch_watch_row(42)]),
    ]);

    app(WatchTaskBranchPullRequestAction::class)->execute($group);

    expect($group->fresh())->watched_pr_number->toBe($retryStatus === 201 ? 42 : null)->pr_url->toBe('https://github.com/acme/orbit/pull/99');
    Http::assertSentCount($retryStatus === 201 ? 5 : 4);
    app(WatchTaskBranchPullRequestAction::class)->execute($other);
    expect($other->fresh())->watched_pr_number->toBe(42);
    Http::assertSentCount($laterRequests);
})->with([
    'retry succeeds, cached id reused' => [201, 7],
    'second refusal stops, id invalidated' => [403, 7],
]);

it('does not list a watched pull request for a group without open subtasks', function (string $status): void {
    Http::preventStrayRequests();
    $group = branch_watch_group('settling', $status);

    app(WatchTaskBranchPullRequestAction::class)->execute($group);

    expect($group->fresh())->watched_pr_url->toBeNull()->pr_url->toBe('https://github.com/acme/orbit/pull/99');
    Http::assertNothingSent();
})->with(['completed', 'failed', 'cancelled']);
