<?php

declare(strict_types=1);

use App\Actions\Tasks\RequestTaskAssistanceAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentDriver;

use function Pest\Laravel\mock;

function assistance_task(): Task
{
    $app = OrbitApp::query()->create([
        'name' => 'Assistance', 'slug' => 'assistance', 'repository_url' => 'https://github.com/acme/orbit.git',
        'default_branch' => 'main',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id, 'title' => 'Assistance', 'brief' => 'Preserve the blocked cycle.', 'status' => 'running',
    ]);

    return $group->tasks()->create(['position' => 1, 'title' => 'Task', 'brief' => 'Needs assistance.', 'status' => 'running'])->fresh();
}

it('keeps repeated explicit requests in history and records one committed assistance transition', function (): void {
    $task = assistance_task();
    $stale = $task->fresh();
    $notifier = mock(CoderSettleNotifier::class);
    $notifier->shouldReceive('assistance')->once()->withArgs(function (TaskGroup $group, string $reason) use ($task): bool {
        return $group->id === $task->task_group_id && $reason === 'First reason'
            && $task->fresh()->assistance_requested
            && Activity::query()->where('description', 'assistance requested')->count() === 1;
    });
    $action = app(StoreTaskCommentAction::class);

    $first = $action->execute($task, ['type' => 'assistance_requested', 'body' => 'First reason', 'author' => 'implementer']);
    $second = $action->execute($stale, ['type' => 'assistance_requested', 'body' => 'More detail', 'author' => 'operator']);

    expect($task->comments()->orderBy('id')->pluck('body')->all())->toBe(['First reason', 'More detail']);
    expect($first->id)->not->toBe($second->id);
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'running', 'assistance_requested' => true, 'assistance_reason' => 'First reason']);
    $this->assertDatabaseHas('task_groups', ['id' => $task->task_group_id, 'status' => 'running', 'assistance_requested' => true, 'assistance_reason' => 'First reason']);
    expect(Activity::query()->where('description', 'assistance requested')->sole()->properties?->all())->toBe([
        'comment_id' => $first->id, 'actor' => 'implementer', 'reason' => 'First reason',
    ]);
});

it('claims a shared transition once when scheduler and explicit comment requests overlap', function (bool $schedulerFirst): void {
    $task = assistance_task();
    $stale = $task->fresh();
    mock(CoderSettleNotifier::class)->shouldReceive('assistance')->once();
    $comment = fn () => app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'assistance_requested', 'body' => 'Comment reason', 'author' => 'implementer',
    ]);
    $scheduler = fn () => app(RequestTaskAssistanceAction::class)->execute($stale, 'Scheduler reason');

    if ($schedulerFirst) {
        expect($scheduler())->toBeTrue();
        $comment();
    } else {
        $comment();
        expect($scheduler())->toBeFalse();
    }

    expect($task->comments()->count())->toBe(1);
    expect(Activity::query()->where('description', 'assistance requested')->count())->toBe(1);
    expect($task->fresh()->assistance_reason)->toBe($schedulerFirst ? 'Scheduler reason' : 'Comment reason');
})->with(['scheduler first' => true, 'comment first' => false]);

it('does not notify or preserve partial assistance state when the outer transaction rolls back', function (): void {
    $task = assistance_task();
    mock(CoderSettleNotifier::class)->shouldNotReceive('assistance');

    expect(fn () => DB::transaction(function () use ($task): void {
        app(StoreTaskCommentAction::class)->execute($task, ['type' => 'assistance_requested', 'body' => 'Rolled back', 'author' => 'implementer']);
        throw new RuntimeException('Abort the comment transaction.');
    }))->toThrow(RuntimeException::class, 'Abort the comment transaction.');

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'assistance_requested' => false]);
    $this->assertDatabaseHas('task_groups', ['id' => $task->task_group_id, 'assistance_requested' => false]);
    expect($task->comments()->count())->toBe(0);
    expect(Activity::query()->where('description', 'assistance requested')->count())->toBe(0);
});

it('starts a new assistance cycle after delivering the full resolution from a stale task model', function (): void {
    $task = assistance_task();
    $thread = test_agent_thread($task->taskGroup, 'blocked-thread', $task);
    $thread->update(['driver' => 'example']);
    $task->update(['implementer_agent_thread_id' => $thread->id, 'communication_failures' => 4]);
    $driver = new FakeAgentDriver;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    mock(CoderSettleNotifier::class)->shouldReceive('assistance')->twice();
    $action = app(StoreTaskCommentAction::class);
    $action->execute($task, ['type' => 'assistance_requested', 'body' => 'First blocker', 'author' => 'implementer']);
    $blockedSnapshot = $task->fresh();
    $body = "The prerequisite is installed.\nContinue with the remaining acceptance checks.";

    $resolution = $action->execute($task, ['type' => 'resolution', 'body' => $body, 'author' => 'operator']);

    expect($driver->calls)->toBe([['operation' => 'send', 'thread' => 'blocked-thread', 'message' => $body]]);
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id, 'assistance_requested' => false, 'communication_failures' => 0,
        'completion_attempt' => $task->completion_attempt + 1, 'resolution_delivered_comment_id' => $resolution->id,
    ]);
    $action->execute($blockedSnapshot, ['type' => 'assistance_requested', 'body' => 'Second blocker', 'author' => 'implementer']);

    expect($task->fresh()->assistance_reason)->toBe('Second blocker');
    expect($task->comments()->orderBy('id')->pluck('body')->all())->toBe(['First blocker', $body, 'Second blocker']);
    expect(Activity::query()->where('description', 'assistance requested')->count())->toBe(2);
    expect(Activity::query()->where('description', 'resolution delivered')->count())->toBe(1);
});

it('keeps the original assistance cycle visible when resolution delivery fails', function (): void {
    $task = assistance_task();
    mock(CoderSettleNotifier::class)->shouldReceive('assistance')->once();
    $action = app(StoreTaskCommentAction::class);
    $action->execute($task, ['type' => 'assistance_requested', 'body' => 'Blocked', 'author' => 'implementer']);

    $action->execute($task, ['type' => 'resolution', 'body' => 'Try again.', 'author' => 'operator']);
    $action->execute($task, ['type' => 'assistance_requested', 'body' => 'Still blocked', 'author' => 'implementer']);

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'assistance_requested' => true, 'assistance_reason' => 'Blocked', 'completion_attempt' => $task->completion_attempt]);
    expect(Activity::query()->where('description', 'resolution delivery failed')->count())->toBe(1);
    expect(Activity::query()->where('description', 'assistance requested')->count())->toBe(1);
    expect($task->comments()->count())->toBe(3);
});
