<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPositions;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentThread;
use App\Models\App as OrbitApp;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function live_group(string $status = 'running'): TaskGroup
{
    $app = OrbitApp::query()->firstOrCreate(['slug' => 'live-tasks'], [
        'name' => 'Live tasks',
        'repository_url' => 'git@example.test:live-tasks.git',
        'default_branch' => 'main',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Secret title',
        'brief' => 'A brief that must never reach a broadcast.',
        'status' => TaskGroupStatus::from($status),
        'started_at' => now(),
    ]);
    Task::query()->create(['task_group_id' => $group->id, 'position' => 1, 'title' => 'One', 'brief' => 'One brief', 'status' => TaskStatus::Running]);
    Task::query()->create(['task_group_id' => $group->id, 'position' => 2, 'title' => 'Two', 'brief' => 'Two brief', 'status' => TaskStatus::Todo]);

    return $group->fresh(['tasks']) ?? $group;
}

/** @return list<RecordBroadcast> */
function live_broadcasts(?RecordEventType $type = null): array
{
    $events = [];
    Event::assertDispatched(RecordBroadcast::class, function (RecordBroadcast $event) use (&$events, $type): bool {
        if ($type === null || $event->type === $type) {
            $events[] = $event;
        }

        return true;
    });

    return $events;
}

describe('task broadcasts', function (): void {
    beforeEach(function (): void {
        Event::fake([RecordBroadcast::class]);
    });

    it('sends one notice for a created group and none before the work ends', function (): void {
        $group = live_group('backlog');

        Event::assertNotDispatched(RecordBroadcast::class);

        app(TaskBroadcasts::class)->flush();

        $events = live_broadcasts();
        expect($events)->toHaveCount(1)
            ->and($events[0]->type)->toBe(RecordEventType::TaskGroupCreated)
            ->and($events[0]->id)->toBe($group->id)
            ->and($events[0]->data)->toBe(['id' => $group->id, 'status' => 'backlog']);
    });

    it('coalesces every change to a group and its subtasks into one update without free text', function (): void {
        $group = live_group();
        app(TaskBroadcasts::class)->flush();
        Event::fake([RecordBroadcast::class]);

        $group->update(['status' => TaskGroupStatus::Reviewing]);
        $group->tasks[0]->update(['status' => TaskStatus::Reviewing]);
        $group->tasks[1]->update(['assistance_requested' => true, 'assistance_reason' => 'Private reason']);
        app(TaskBroadcasts::class)->flush();

        $events = live_broadcasts();
        expect($events)->toHaveCount(1)
            ->and($events[0]->type)->toBe(RecordEventType::TaskGroupUpdated)
            ->and($events[0]->data)->toBe(['id' => $group->id, 'status' => 'reviewing', 'lines_added' => null, 'lines_deleted' => null, 'line_diff' => null])
            ->and(json_encode($events[0]->broadcastWith()))->not->toContain('Secret title', 'brief', 'Private reason');
    });

    it('does not broadcast a change only to tokens, line counts, or duration', function (): void {
        $group = live_group();
        app(TaskBroadcasts::class)->flush();
        Event::fake([RecordBroadcast::class]);

        $group->update(['tokens' => 900, 'line_diff' => 12, 'lines_added' => 10, 'lines_deleted' => 2, 'duration_ms' => 5000]);
        $group->tasks[0]->update(['tokens' => 400, 'duration_ms' => 3000]);
        app(TaskBroadcasts::class)->flush();

        Event::assertNotDispatched(RecordBroadcast::class);
    });

    it('marks the group when subtask positions move or a subtask is deleted', function (): void {
        $group = live_group('backlog');
        app(TaskBroadcasts::class)->flush();
        Event::fake([RecordBroadcast::class]);

        TaskPositions::assign($group, [$group->tasks[1]->id, $group->tasks[0]->id]);
        app(TaskBroadcasts::class)->flush();
        expect(live_broadcasts(RecordEventType::TaskGroupUpdated))->toHaveCount(1);

        Event::fake([RecordBroadcast::class]);
        $group->tasks[1]->delete();
        app(TaskBroadcasts::class)->flush();
        expect(live_broadcasts(RecordEventType::TaskGroupUpdated))->toHaveCount(1);
    });

    it('waits for the commit and discards the changes of a rolled back transaction', function (): void {
        DB::beginTransaction();
        live_group('backlog');
        app(TaskBroadcasts::class)->flush();
        DB::rollBack();

        Event::assertNotDispatched(RecordBroadcast::class);

        DB::beginTransaction();
        live_group('backlog');
        app(TaskBroadcasts::class)->flush();
        Event::assertNotDispatched(RecordBroadcast::class);
        DB::commit();

        expect(live_broadcasts(RecordEventType::TaskGroupCreated))->toHaveCount(1);
    });

    it('sends a comment notice without the body', function (): void {
        $group = live_group();
        app(TaskBroadcasts::class)->flush();
        Event::fake([RecordBroadcast::class]);

        $comment = TaskComment::query()->create([
            'task_group_id' => $group->id, 'task_id' => $group->tasks[0]->id, 'type' => 'ready_for_review',
            'body' => 'Receipt body', 'author' => 'implementer', 'posted_at' => now(),
        ]);
        app(TaskBroadcasts::class)->flush();

        $events = live_broadcasts(RecordEventType::TaskCommentCreated);
        expect($events)->toHaveCount(1)
            ->and($events[0]->data)->toBe(['id' => $comment->id, 'task_group_id' => $group->id, 'task_id' => $group->tasks[0]->id]);
    });

    it('sends a thread notice when its state changes but not when only its tokens change', function (): void {
        $group = live_group();
        test_link_agent_threads($group, implementer: 'implementer-live');
        app(TaskBroadcasts::class)->flush();
        Event::fake([RecordBroadcast::class]);
        $thread = AgentThread::query()->where('external_id', 'implementer-live')->firstOrFail();
        $observer = app(AgentThreadObserver::class);

        $observer->record($thread, new AgentObservation(state: AgentThreadState::Working, tokens: 10));
        app(TaskBroadcasts::class)->flush();
        $events = live_broadcasts(RecordEventType::AgentThreadUpdated);
        expect($events)->toHaveCount(1)
            ->and($events[0]->data)->toBe(['id' => $thread->id, 'task_group_id' => $group->id, 'task_id' => $group->tasks[0]->id, 'state' => 'working']);

        Event::fake([RecordBroadcast::class]);
        $observer->record($thread->fresh() ?? $thread, new AgentObservation(state: AgentThreadState::Working, tokens: 99));
        app(TaskBroadcasts::class)->flush();
        Event::assertNotDispatched(RecordBroadcast::class);
    });

    it('announces the extension state when it is enabled or disabled over the API', function (): void {
        $gateway = $this->markAsGateway(Node::query()->create([
            'name' => 'live-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.151', 'wireguard_ip' => '10.44.0.151',
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);

        $this->postJson('/api/v1/tasks/enable')->assertOk();
        $this->postJson('/api/v1/tasks/disable')->assertOk();

        $events = live_broadcasts(RecordEventType::TasksUpdated);
        expect(array_map(static fn (RecordBroadcast $event): array => $event->data, $events))->toBe([['enabled' => true], ['enabled' => false]]);
    });

    it('broadcasts the changes of an API request when the request ends', function (): void {
        $gateway = $this->markAsGateway(Node::query()->create([
            'name' => 'live-gateway-2', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.152', 'wireguard_ip' => '10.44.0.152',
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
        app(TaskExtensionState::class)->enable();
        $group = live_group('backlog');
        app(TaskBroadcasts::class)->flush();
        Event::fake([RecordBroadcast::class]);

        $this->patchJson("/api/v1/task-groups/{$group->id}", ['title' => 'Renamed'])->assertOk();

        expect(live_broadcasts(RecordEventType::TaskGroupUpdated))->toHaveCount(1);
    });

    it('broadcasts the changes of a scheduler tick when the tick ends', function (): void {
        app(TaskExtensionState::class)->enable();
        $group = live_group();
        app(TaskBroadcasts::class)->flush();
        Event::fake([RecordBroadcast::class]);
        $group->update(['assistance_requested' => true, 'assistance_reason' => 'Waiting']);

        $this->artisan('tasks:tick')->assertSuccessful();

        expect(live_broadcasts(RecordEventType::TaskGroupUpdated))->toHaveCount(1);
    });
});
