<?php

declare(strict_types=1);

use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;

/** A blocked task in the given status, with an implementer and a reviewer thread. */
function blocked_task(TaskStatus $status): Task
{
    $app = OrbitApp::query()->create([
        'name' => 'blocked', 'slug' => 'blocked',
        'repository_url' => 'git@example.test:blocked.git', 'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'blocked-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.190', 'wireguard_ip' => '10.44.0.190',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id, 'node_id' => $node->id, 'name' => 'task-9',
        'checkout_path' => '/tmp/task-9', 'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id, 'title' => 'Blocked', 'brief' => 'Unblock it.',
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
        ->and($task->taskGroup->assistance_requested)->toBeFalse();
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
