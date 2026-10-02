<?php

declare(strict_types=1);

use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\NullTaskReviewDiff;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskQuestion;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcceptingTaskWorkspaceMcp;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\AgentSnapshotReader;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;

/** A blocked task in the given status, with an implementer and a reviewer thread. */
function blocked_task(TaskStatus $status): Task
{
    $project = Project::query()->create([
        'name' => 'blocked', 'slug' => 'blocked',
        'repository_url' => 'git@example.test:blocked.git', 'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'blocked-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.190', 'wireguard_ip' => '10.44.0.190',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-9',
        'checkout_path' => '/tmp/task-9', 'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id, 'title' => 'Blocked', 'brief' => 'Unblock it.',
        'status' => $status === TaskStatus::Reviewing ? TaskGroupStatus::Reviewing : TaskGroupStatus::Running,
        'assistance_requested' => true, 'assistance_reason' => 'Blocked.',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Only', 'brief' => 'One',
        'status' => $status, 'assistance_requested' => true, 'assistance_reason' => 'Blocked.',
        'completion_attempt' => 2, 'review_attempt' => 3, 'review_notified_attempt' => 3,
    ]);
    test_link_agent_threads($group->fresh(['tasks']) ?? $group, reviewer: 'reviewer-thread', implementer: 'implementer-thread');
    if ($status === TaskStatus::Reviewing) {
        AgentThread::query()->where('task_group_id', $group->id)->where('external_id', 'reviewer-thread')->update(['task_id' => $task->id]);
    }

    return $task->fresh() ?? $task;
}

/** Binds a T3 driver whose dispatcher records the thread of every started turn. */
function recording_t3_turns(): object
{
    $dispatcher = new class implements AgentCommandDispatcher
    {
        /** @var list<string> */
        public array $threads = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->threads[] = (string) ($command['threadId'] ?? '');

            return ['sequence' => 1, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    app()->instance(AgentDriverRegistry::class, test_snapshot_registry(dispatcher: $dispatcher));

    return $dispatcher;
}

it('relays a direction resolution to the reviewer of a running subtask', function (): void {
    $task = blocked_task(TaskStatus::Running);
    $task->update(['assistance_kind' => AssistanceKind::Direction, 'assistance_question' => 'Which mirror?']);
    AgentThread::query()->where('task_group_id', $task->parent_id)->where('role', 'reviewer')->update(['task_id' => $task->id]);
    TaskQuestion::query()->create([
        'task_id' => $task->parent_id, 'subtask_id' => $task->id, 'attempt' => 2, 'asked_by' => QuestionAsker::Implementer,
        'question' => 'Which mirror?', 'status' => QuestionStatus::Escalated, 'asked_at' => now(), 'escalated_at' => now(),
    ]);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);
    $turns = recording_t3_turns();

    $comment = app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Use the public mirror.', 'author' => 'operator',
    ]);

    expect($turns->threads)->toBe(['reviewer-thread'])
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->direction_relay_comment_id)->toBe($comment->id)
        ->and($task->fresh()?->completion_attempt)->toBe(2)
        ->and(TaskQuestion::query()->sole()->status)->toBe(QuestionStatus::Escalated)
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->toBe($comment->id);
});

it('rolls back a direction delivery that fails before the question is linked and accepts a replay', function (TaskStatus $status): void {
    $task = blocked_task($status);
    $task->update(['assistance_kind' => AssistanceKind::Direction, 'assistance_question' => 'Which mirror?']);
    if ($status === TaskStatus::Running) {
        AgentThread::query()->where('task_group_id', $task->parent_id)->where('role', 'reviewer')->update(['task_id' => $task->id]);
    }
    TaskQuestion::query()->create([
        'task_id' => $task->parent_id, 'subtask_id' => $task->id, 'attempt' => 2, 'asked_by' => QuestionAsker::Implementer,
        'question' => 'Which mirror?', 'status' => QuestionStatus::Escalated, 'asked_at' => now(), 'escalated_at' => now(),
    ]);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);
    $turns = recording_t3_turns();
    $inject = true;
    DB::beforeExecuting(function (string $sql) use (&$inject): void {
        if ($inject && str_contains($sql, 'task_questions') && str_starts_with(ltrim(strtolower($sql)), 'update')) {
            $inject = false;

            throw new RuntimeException('injected question link failure');
        }
    });

    expect(fn () => app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Use the documented choice.', 'author' => 'operator',
    ]))->toThrow(RuntimeException::class);

    $task->refresh();
    expect($task->assistance_requested)->toBeTrue()
        ->and($task->parent->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->direction_relay_comment_id)->toBeNull()
        ->and($task->resolution_delivered_comment_id)->toBeNull()
        ->and($task->review_attempt)->toBe(3)
        ->and($task->completion_attempt)->toBe(2)
        ->and(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->toBeNull();

    $comment = app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'resolution', 'body' => 'Use the documented choice.', 'author' => 'operator',
    ]);
    $task->refresh();

    expect(TaskQuestion::query()->count())->toBe(1)
        ->and(TaskQuestion::query()->sole()->resolution_comment_id)->toBe($comment->id)
        ->and($task->assistance_requested)->toBeFalse()
        ->and($task->parent->fresh()?->assistance_requested)->toBeFalse()
        ->and($turns->threads)->toBe(['reviewer-thread', 'reviewer-thread']);
    if ($status === TaskStatus::Running) {
        expect($task->direction_relay_comment_id)->toBe($comment->id)
            ->and($task->completion_attempt)->toBe(2)
            ->and($task->review_attempt)->toBe(3);
    } else {
        expect($task->direction_relay_comment_id)->toBeNull()
            ->and($task->review_attempt)->toBe(4)
            ->and($task->review_notified_attempt)->toBe(4)
            ->and($task->resolution_delivered_comment_id)->toBe($comment->id);
    }
})->with([
    'running relay' => TaskStatus::Running,
    'review' => TaskStatus::Reviewing,
]);

it('records an operator direction request as a question', function (): void {
    $task = blocked_task(TaskStatus::Running);
    $task->update(['assistance_requested' => false, 'assistance_reason' => null]);
    $task->parent->update(['assistance_requested' => false, 'assistance_reason' => null]);

    $comment = app(StoreTaskCommentAction::class)->execute($task->fresh() ?? $task, [
        'type' => 'assistance_requested', 'body' => 'Which database should this use?', 'author' => 'operator',
    ]);

    $question = TaskQuestion::query()->sole();
    expect($question->asked_by)->toBe(QuestionAsker::Operator)
        ->and($question->question)->toBe('Which database should this use?')
        ->and($question->status)->toBe(QuestionStatus::Escalated)
        ->and($question->cause)->toBeNull()
        ->and($question->opened_comment_id)->toBe($comment->id)
        ->and($task->fresh()?->questions)->toBe(1)
        ->and($task->parent->fresh()?->escalations)->toBe(1);
});

it('sends a resolution for a blocked review to the reviewer and does not request the review again', function (): void {
    $task = blocked_task(TaskStatus::Reviewing);
    $turns = recording_t3_turns();

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Approve without the migration check.', 'author' => 'operator',
    ]);
    $task->refresh();

    expect($turns->threads)->toBe(['reviewer-thread'])
        ->and($task->assistance_requested)->toBeFalse()
        ->and($task->review_attempt)->toBe(4)
        ->and($task->review_notified_attempt)->toBe(4)
        ->and($task->completion_attempt)->toBe(2)
        ->and($task->resolution_delivered_comment_id)->toBe($comment->id)
        ->and($task->parent->assistance_requested)->toBeFalse();
});

it('starts a fresh subtask reviewer with a review resolution when that thread does not exist', function (): void {
    $project = Project::query()->create([
        'name' => 'resolution-target', 'slug' => 'resolution-target',
        'repository_url' => 'git@example.test:resolution-target.git', 'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'resolution-target-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.191', 'wireguard_ip' => '10.44.0.191',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-136',
        'checkout_path' => '/tmp/task-136', 'branch' => 'task-136', 'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id, 'title' => 'Resolutions', 'brief' => 'Route each resolution to its subtask.',
        'status' => TaskGroupStatus::Reviewing,
        'assistance_requested' => true, 'assistance_reason' => 'The review diff could not be read.',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $earlier = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Names', 'brief' => 'Name the records.',
        'status' => TaskStatus::Completed,
    ]);
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 2, 'title' => 'Routes', 'brief' => 'Route the resolution.',
        'status' => TaskStatus::Reviewing, 'assistance_requested' => true, 'assistance_reason' => 'The review diff could not be read.',
        'review_attempt' => 3, 'review_notified_attempt' => null, 'communication_failures' => 5, 'completion_attempt' => 2,
    ]);
    $earlierReviewer = test_agent_thread($group, 'subtask-1-reviewer');
    $earlierReviewer->update(['task_id' => $earlier->id]);
    $group->update(['reviewer_agent_thread_id' => $earlierReviewer->id]);
    $implementer = test_agent_thread($group, 'subtask-2-implementer', $task);
    $task->update(['implementer_agent_thread_id' => $implementer->id]);
    $dispatcher = new class implements AgentCommandDispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;

            return ['sequence' => 1, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    $reader = new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => 'idle'],
                'latestTurn' => ['id' => $threadId.'-turn', 'state' => 'completed'],
            ]];
        }
    };
    app()->instance(AgentDriverRegistry::class, test_snapshot_registry(dispatcher: $dispatcher, reader: $reader));

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Ship the names as they are.', 'author' => 'operator',
    ]);
    $task->refresh();
    $started = array_values(array_filter(
        $dispatcher->commands,
        static fn (array $command): bool => ($command['type'] ?? '') === 'send',
    ));

    expect($started)->toBe([])
        ->and($task->assistance_requested)->toBeFalse()
        ->and($task->parent->assistance_requested)->toBeFalse()
        ->and($task->communication_failures)->toBe(0)
        ->and($task->review_attempt)->toBe(3)
        ->and($task->review_notified_attempt)->toBeNull()
        ->and($task->resolution_delivered_comment_id)->toBeNull()
        ->and($task->completion_attempt)->toBe(2);

    app(TaskExtensionState::class)->enable();
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);
    app()->instance(TaskReviewDiff::class, new NullTaskReviewDiff);
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
    app(TaskScheduler::class)->tick();

    $task->refresh();
    $reviewer = AgentThread::query()->where('task_id', $task->id)->where('role', 'reviewer')->sole();
    $opening = array_values(array_filter(
        $dispatcher->commands,
        static fn (array $command): bool => ($command['type'] ?? '') === 'create',
    ));
    $threadIds = array_map(static fn (array $command): string => (string) ($command['threadId'] ?? ''), $opening);
    $text = implode("\n", array_map(static fn (array $command): string => (string) data_get($command, 'message.text'), $opening));

    expect($reviewer->id)->not->toBe($earlierReviewer->id)
        ->and($task->parent->reviewer_agent_thread_id)->toBe($reviewer->id)
        ->and($task->review_attempt)->toBe(3)
        ->and($task->review_notified_attempt)->toBe(3)
        ->and($task->resolution_delivered_comment_id)->toBe($comment->id)
        ->and($threadIds)->toBe([$reviewer->external_id])
        ->and($threadIds)->not->toContain('subtask-1-reviewer')
        ->and($text)->toContain('Ship the names as they are.');
});

it('sends a resolution for a blocked implementation to the implementer', function (): void {
    $task = blocked_task(TaskStatus::Running);
    $turns = recording_t3_turns();

    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Dependencies are installed now.', 'author' => 'operator',
    ]);
    $task->refresh();

    expect($turns->threads)->toBe(['implementer-thread'])
        ->and($task->assistance_requested)->toBeFalse()
        ->and($task->completion_attempt)->toBe(3)
        ->and($task->review_attempt)->toBe(3);
});
