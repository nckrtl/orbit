<?php

declare(strict_types=1);

use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\NullTaskReviewDiff;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Infrastructure\Tasks\T3\T3ThreadReader;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Tests\Support\AcceptingTaskWorkspaceMcp;
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
        'project_id' => $project->id, 'title' => 'Blocked', 'brief' => 'Unblock it.',
        'status' => $status === TaskStatus::Reviewing ? TaskGroupStatus::Reviewing : TaskGroupStatus::Running,
        'assistance_requested' => true, 'assistance_reason' => 'Blocked.',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'task_group_id' => $group->id, 'position' => 1, 'title' => 'Only', 'brief' => 'One',
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
    $dispatcher = new class implements T3Dispatcher
    {
        /** @var list<string> */
        public array $threads = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->threads[] = (string) ($command['threadId'] ?? '');

            return ['sequence' => 1, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    app()->instance(AgentDriverRegistry::class, test_t3_registry(dispatcher: $dispatcher));

    return $dispatcher;
}

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
        'project_id' => $project->id, 'title' => 'Resolutions', 'brief' => 'Route each resolution to its subtask.',
        'status' => TaskGroupStatus::Reviewing,
        'assistance_requested' => true, 'assistance_reason' => 'The review diff could not be read.',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $earlier = Task::query()->create([
        'task_group_id' => $group->id, 'position' => 1, 'title' => 'Names', 'brief' => 'Name the records.',
        'status' => TaskStatus::Completed,
    ]);
    $task = Task::query()->create([
        'task_group_id' => $group->id, 'position' => 2, 'title' => 'Routes', 'brief' => 'Route the resolution.',
        'status' => TaskStatus::Reviewing, 'assistance_requested' => true, 'assistance_reason' => 'The review diff could not be read.',
        'review_attempt' => 3, 'review_notified_attempt' => null, 'communication_failures' => 5, 'completion_attempt' => 2,
    ]);
    $earlierReviewer = test_agent_thread($group, 'subtask-1-reviewer');
    $earlierReviewer->update(['task_id' => $earlier->id]);
    $group->update(['reviewer_agent_thread_id' => $earlierReviewer->id]);
    $implementer = test_agent_thread($group, 'subtask-2-implementer', $task);
    $task->update(['implementer_agent_thread_id' => $implementer->id]);
    $dispatcher = new class implements T3Dispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;

            return ['sequence' => 1, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    $reader = new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => 'idle'],
                'latestTurn' => ['id' => $threadId.'-turn', 'state' => 'completed'],
            ]];
        }
    };
    app()->instance(AgentDriverRegistry::class, test_t3_registry(dispatcher: $dispatcher, reader: $reader));

    $comment = app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Ship the names as they are.', 'author' => 'operator',
    ]);
    $task->refresh();
    $started = array_values(array_filter(
        $dispatcher->commands,
        static fn (array $command): bool => ($command['type'] ?? '') === 'thread.turn.start',
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
        static fn (array $command): bool => ($command['type'] ?? '') === 'thread.turn.start',
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
