<?php

declare(strict_types=1);

use App\Actions\Tasks\RetryRedMainBaselineAction;
use App\Actions\Tasks\RetryTaskBaselineAction;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

use function Pest\Laravel\mock;

const RED_MAIN_BASELINE_HEAD = '82fcdd38462a074aed2967de32fab703c5bc894d';
const GREEN_MAIN_BASELINE_TIP = 'e43566fefdce199eaacf5d2ddad4b56fa1a2a55a';

/** Task 1293's shape: baseline failed before any implementer, while ADR 0197 retirement/reuse was broken on main. */
function red_main_baseline_task(): Task
{
    $project = Project::query()->create([
        'name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'git@github.com:acme/orbit.git',
        'default_branch' => 'main', 'task_check' => 'composer check',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'ADR 0197 retire/reuse baseline failure',
        'brief' => 'Recorded task 1293 baseline shape.', 'status' => TaskGroupStatus::Running,
        'execution_mode' => TaskExecutionMode::Managed, 'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Failure, 'assistance_reason' => 'Project baseline failed.',
    ]);
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => '1293 baseline',
        'brief' => 'Retry only after main contains the fix for the ADR 0197 retire/reuse failure.',
        'status' => TaskStatus::Running, 'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Failure, 'assistance_reason' => 'Project baseline failed.',
        'subtask_start_commit' => RED_MAIN_BASELINE_HEAD,
    ]);
    TaskCheck::query()->create([
        'task_id' => $task->id, 'kind' => TaskCheckKind::Baseline,
        'pid' => 123, 'process_started' => 'recorded-baseline', 'tree_before' => str_repeat('a', 40), 'started_at' => now(),
        'status' => TaskCheckStatus::Failed, 'head_before' => RED_MAIN_BASELINE_HEAD,
        'head_after' => RED_MAIN_BASELINE_HEAD, 'exit_code' => 1,
    ]);

    return $task;
}

/** @param list<array{name: string, conclusion: string|null}> $runs */
function red_main_checks(array $runs, int $status = 200): void
{
    GitHubTestSupport::storeApp();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_checks'], 201),
        'https://api.github.com/repos/acme/orbit/commits/'.GREEN_MAIN_BASELINE_TIP.'/check-runs*' => Http::response([
            'total_count' => count($runs), 'check_runs' => $runs,
        ], $status),
    ]);
}

it('queues one system resolution and resets the 1293 baseline to a green descendant through the normal retry path', function (): void {
    $task = red_main_baseline_task();
    red_main_checks([
        ['name' => 'Gateway', 'conclusion' => 'success'],
        ['name' => 'Required checks', 'conclusion' => 'failure'],
        ['name' => 'Infrastructure', 'conclusion' => 'startup_failure'],
    ]);
    $bases = mock(TaskBaseBranchFetcher::class);
    $bases->shouldReceive('fetchForTurn')->twice();
    $bases->shouldReceive('defaultTip')->twice()->andReturn(GREEN_MAIN_BASELINE_TIP);
    $bases->shouldReceive('isAncestor')->twice()->withArgs(fn (Task $group, string $old, string $tip): bool => $group->id === $task->parent_id && $old === RED_MAIN_BASELINE_HEAD && $tip === GREEN_MAIN_BASELINE_TIP)->andReturnTrue();
    $bases->shouldReceive('resetToDefault')->once()->andReturn(GREEN_MAIN_BASELINE_TIP);

    expect(app(RetryRedMainBaselineAction::class)->execute($task))->toBeTrue();

    $comment = TaskComment::query()->sole();
    $this->assertDatabaseHas('task_comments', [
        'id' => $comment->id, 'task_id' => $task->id, 'task_group_id' => $task->parent_id,
        'type' => 'resolution', 'author' => 'gateway',
    ]);
    expect($comment->body)->toContain(RED_MAIN_BASELINE_HEAD.' → '.GREEN_MAIN_BASELINE_TIP, 'main', 'green');
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id, 'subtask_start_commit' => GREEN_MAIN_BASELINE_TIP,
        'resolution_delivered_comment_id' => $comment->id, 'status' => 'running',
        'assistance_requested' => false, 'assistance_kind' => null, 'implementer_agent_thread_id' => null,
    ]);
    $this->assertDatabaseHas('tasks', ['id' => $task->parent_id, 'assistance_requested' => false]);
    $this->assertDatabaseHas('task_checks', ['task_id' => $task->id, 'head_before' => RED_MAIN_BASELINE_HEAD, 'status' => 'failed']);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens') && $request->data()['permissions'] === ['checks' => 'read']);
    expect(app(RetryRedMainBaselineAction::class)->execute($task))->toBeFalse();
    $this->assertDatabaseCount('task_comments', 1);
});

it('leaves baseline assistance unchanged when the descendant tip is red or pending or cannot be checked', function (array $runs, int $status): void {
    $task = red_main_baseline_task();
    red_main_checks($runs, $status);
    $bases = mock(TaskBaseBranchFetcher::class);
    $bases->shouldReceive('fetchForTurn')->once();
    $bases->shouldReceive('defaultTip')->once()->andReturn(GREEN_MAIN_BASELINE_TIP);
    $bases->shouldReceive('isAncestor')->once()->andReturnTrue();
    $bases->shouldNotReceive('resetToDefault');

    expect(app(RetryRedMainBaselineAction::class)->execute($task))->toBeFalse();

    $this->assertDatabaseCount('task_comments', 0);
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id, 'assistance_requested' => true, 'assistance_kind' => 'failure',
        'subtask_start_commit' => RED_MAIN_BASELINE_HEAD, 'resolution_delivered_comment_id' => null,
    ]);
})->with([
    'red' => [[['name' => 'Gateway', 'conclusion' => 'failure']], 200],
    'pending' => [[['name' => 'Gateway', 'conclusion' => null]], 200],
    'timed out' => [[['name' => 'Gateway', 'conclusion' => 'timed_out']], 200],
    'unavailable' => [[], 503],
    'empty' => [[], 200],
    'rollup only' => [[['name' => 'Required checks', 'conclusion' => 'success']], 200],
    'infrastructure only' => [[['name' => 'Gateway', 'conclusion' => 'startup_failure']], 200],
    'unknown' => [[['name' => 'Gateway', 'conclusion' => 'unknown']], 200],
]);

it('does not retry an unchanged or unrelated default branch tip', function (string $tip, bool $ancestor): void {
    $task = red_main_baseline_task();
    Http::preventStrayRequests();
    $bases = mock(TaskBaseBranchFetcher::class);
    $bases->shouldReceive('fetchForTurn')->once();
    $bases->shouldReceive('defaultTip')->once()->andReturn($tip);
    if ($tip !== RED_MAIN_BASELINE_HEAD) {
        $bases->shouldReceive('isAncestor')->once()->andReturn($ancestor);
    } else {
        $bases->shouldNotReceive('isAncestor');
    }
    $bases->shouldNotReceive('resetToDefault');

    expect(app(RetryRedMainBaselineAction::class)->execute($task))->toBeFalse();

    $this->assertDatabaseCount('task_comments', 0);
    expect($task->fresh()?->assistance_requested)->toBeTrue();
    expect($task->fresh()?->resolution_delivered_comment_id)->toBeNull();
})->with([
    'same tip' => [RED_MAIN_BASELINE_HEAD, true],
    'not descendant' => [GREEN_MAIN_BASELINE_TIP, false],
]);

it('does not retry when baseline eligibility is lost or a resolution already awaits recovery', function (string $case): void {
    $task = red_main_baseline_task();
    match ($case) {
        'direction' => $task->update(['assistance_kind' => AssistanceKind::Direction]),
        'group direction' => $task->parent()->update(['assistance_kind' => AssistanceKind::Direction]),
        'implementer' => test_link_agent_threads($task->parent()->firstOrFail(), implementer: 'started-implementer'),
        'not running' => $task->update(['status' => TaskStatus::Todo]),
        'unmanaged' => $task->parent()->update(['execution_mode' => TaskExecutionMode::ExistingThread]),
        'running check' => TaskCheck::query()->create(['task_id' => $task->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running, 'pid' => 124, 'process_started' => 'running-check', 'head_before' => RED_MAIN_BASELINE_HEAD, 'tree_before' => str_repeat('a', 40), 'started_at' => now()]),
        'pending resolution' => $task->update(['resolution_delivered_comment_id' => TaskComment::query()->create([
            'task_group_id' => $task->parent_id, 'task_id' => $task->id, 'type' => 'resolution', 'author' => 'operator', 'body' => 'Retry.', 'posted_at' => now(),
        ])->id]),
    };
    $comments = TaskComment::query()->count();
    $bases = mock(TaskBaseBranchFetcher::class);
    $bases->shouldNotReceive('fetchForTurn', 'resetToDefault');

    expect(app(RetryRedMainBaselineAction::class)->execute($task))->toBeFalse();

    $this->assertDatabaseCount('task_comments', $comments);
    expect($task->fresh()?->assistance_requested)->toBeTrue();
    expect($task->fresh()?->subtask_start_commit)->toBe(RED_MAIN_BASELINE_HEAD);
})->with(['direction', 'group direction', 'implementer', 'not running', 'unmanaged', 'running check', 'pending resolution']);

it('keeps a single committed resolution when recovery sees main turn red and never resets onto that red tip', function (): void {
    $task = red_main_baseline_task();
    GitHubTestSupport::storeApp();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_checks'], 201),
        'https://api.github.com/repos/acme/orbit/commits/'.GREEN_MAIN_BASELINE_TIP.'/check-runs*' => Http::sequence()
            ->push(['check_runs' => [['name' => 'Gateway', 'conclusion' => 'success']]])
            ->push(['check_runs' => [['name' => 'Gateway', 'conclusion' => 'failure']]])
            ->push(['check_runs' => [['name' => 'Gateway', 'conclusion' => 'success']]]),
    ]);
    $bases = mock(TaskBaseBranchFetcher::class);
    $bases->shouldReceive('fetchForTurn')->times(3);
    $bases->shouldReceive('defaultTip')->times(3)->andReturn(GREEN_MAIN_BASELINE_TIP);
    $bases->shouldReceive('isAncestor')->times(3)->andReturnTrue();
    $bases->shouldReceive('resetToDefault')->once()->andReturn(GREEN_MAIN_BASELINE_TIP);

    expect(app(RetryRedMainBaselineAction::class)->execute($task))->toBeTrue();

    expect($task->fresh()?->assistance_requested)->toBeTrue();
    expect($task->fresh()?->subtask_start_commit)->toBe(RED_MAIN_BASELINE_HEAD);
    expect(app(RetryRedMainBaselineAction::class)->execute($task))->toBeFalse();
    expect(app(RetryTaskBaselineAction::class)->recover($task))->toBeTrue();
    $this->assertDatabaseCount('task_comments', 1);
    expect($task->fresh()?->subtask_start_commit)->toBe(GREEN_MAIN_BASELINE_TIP);
});

it('pins historical 1286 and 1298 baseline recovery to the checked descendant', function (string $head, string $tip, string $failure): void {
    $task = red_main_baseline_task();
    $task->update(['subtask_start_commit' => $head, 'assistance_reason' => $failure]);
    $task->checks()->update(['head_before' => $head, 'head_after' => $head, 'output' => $failure]);
    GitHubTestSupport::storeApp();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_checks'], 201),
        'https://api.github.com/repos/acme/orbit/commits/'.$tip.'/check-runs*' => Http::response(['check_runs' => [['name' => 'CLI', 'conclusion' => 'success']]]),
    ]);
    $bases = mock(TaskBaseBranchFetcher::class);
    $bases->shouldReceive('fetchForTurn')->twice();
    $bases->shouldReceive('defaultTip')->twice()->andReturn($tip);
    $bases->shouldReceive('isAncestor')->twice()->andReturnTrue();
    $bases->shouldReceive('resetToDefault')->once()->withArgs(fn (Task $group, ?string $verified): bool => $group->id === $task->parent_id && $verified === $tip)->andReturn($tip);
    expect(app(RetryRedMainBaselineAction::class)->execute($task))->toBeTrue();
    expect($task->fresh()->subtask_start_commit)->toBe($tip);
    expect($task->checks()->sole()->head_before)->toBe($head);
})->with([
    '1286 check 1433 protected-file timeout' => ['f843defd6803b66f9a238c247ef4ee7ad2d0a6a2', 'a42d879f42d748f4eb50531be67d78ff3ce3fd2b', 'Timed out waiting for protected-file writer state.'],
    '1298 check 1421 CLI leak; resolution 2867' => ['e43566fefdce199eaacf5d2ddad4b56fa1a2a55a', 'f843defd6803b66f9a238c247ef4ee7ad2d0a6a2', 'ProxyCliCommandsTest and TaskContractTest MockClient / GatewayExtensionState'],
]);
