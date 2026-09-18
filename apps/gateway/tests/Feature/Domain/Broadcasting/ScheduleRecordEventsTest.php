<?php

declare(strict_types=1);

use App\Actions\Schedules\ActivateScheduleAction;
use App\Actions\Schedules\AddScheduleAction;
use App\Actions\Schedules\CompleteScheduleAction;
use App\Actions\Schedules\RemoveScheduleAction;
use App\Data\Schedules\AddScheduleData;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleRunStatus;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Schedules\ScheduleSpecificationValidator;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Support\Facades\Event;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;
use Tests\Support\Schedules\FakeScheduleRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new FakeScheduleRuntimeManager;
    app()->instance(ScheduleRuntimeManager::class, $this->runtime);
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);

    $this->node = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.2',
    ]);
});

describe('Schedule record events', function (): void {
    it('broadcasts schedule.created when a new schedule is added', function (): void {
        Event::fake([RecordBroadcast::class]);

        $data = new AddScheduleData(
            targetType: ScheduleTargetType::Node,
            targetId: $this->node->id,
            name: 'nightly-backup',
            calendar: '*-*-* 02:00:00',
            command: 'backup.sh',
        );

        $result = new AddScheduleAction(
            new ScheduleSpecificationValidator,
            new ScheduleTargetResolver(app(ScheduleRuntimeAccountResolver::class)),
            $this->runtime,
            app(ProcessAdmissionLock::class),
        )->execute($data);

        expect($result['created'])->toBeTrue();
        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ScheduleCreated
                && $event->id === $result['schedule']->id
                && $event->data['name'] === 'nightly-backup',
        );
    });

    it('broadcasts schedule.updated when an app instance schedule is activated', function (): void {
        $schedule = Schedule::query()->create([
            'target_type' => Node::class,
            'target_id' => $this->node->id,
            'host_node_id' => $this->node->id,
            'name' => 'nightly-backup',
            'calendar' => '*-*-* 02:00:00',
            'command' => 'backup.sh',
            'timeout_seconds' => 3600,
            'desired_timer_state' => DesiredTimerState::Disabled,
            'status' => LifecycleStatus::Active,
        ]);
        // Activation is only supported for AppInstance-targeted schedules.
        $schedule->update(['target_type' => AppInstance::class, 'target_id' => 1]);

        Event::fake([RecordBroadcast::class]);

        new ActivateScheduleAction($this->runtime)->execute($schedule);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ScheduleUpdated
                && $event->id === $schedule->id,
        );
    });

    it('broadcasts schedule.updated when a run completes', function (): void {
        $schedule = Schedule::query()->create([
            'target_type' => Node::class,
            'target_id' => $this->node->id,
            'host_node_id' => $this->node->id,
            'name' => 'nightly-backup',
            'calendar' => '*-*-* 02:00:00',
            'command' => 'backup.sh',
            'timeout_seconds' => 3600,
            'desired_timer_state' => DesiredTimerState::Enabled,
            'status' => LifecycleStatus::Active,
        ]);

        Event::fake([RecordBroadcast::class]);

        new CompleteScheduleAction()->execute($schedule->id, ScheduleRunStatus::Success, $this->node);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ScheduleUpdated
                && $event->id === $schedule->id
                && $event->data['last_run_status'] === 'success',
        );
    });

    it('broadcasts schedule.deleted when a schedule is fully removed', function (): void {
        $schedule = Schedule::query()->create([
            'target_type' => Node::class,
            'target_id' => $this->node->id,
            'host_node_id' => $this->node->id,
            'name' => 'nightly-backup',
            'calendar' => '*-*-* 02:00:00',
            'command' => 'backup.sh',
            'timeout_seconds' => 3600,
            'desired_timer_state' => DesiredTimerState::Enabled,
            'status' => LifecycleStatus::Active,
        ]);

        Event::fake([RecordBroadcast::class]);

        new RemoveScheduleAction($this->runtime)->execute($schedule);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ScheduleDeleted
                && $event->id === $schedule->id
                && $event->data === ['id' => $schedule->id, 'name' => 'nightly-backup'],
        );
    });

    it('broadcasts schedule.updated instead when a removal does not fully complete in the same request', function (): void {
        $schedule = Schedule::query()->create([
            'target_type' => Node::class,
            'target_id' => $this->node->id,
            'host_node_id' => $this->node->id,
            'name' => 'nightly-backup',
            'calendar' => '*-*-* 02:00:00',
            'command' => 'backup.sh',
            'timeout_seconds' => 3600,
            'desired_timer_state' => DesiredTimerState::Enabled,
            'status' => LifecycleStatus::Active,
        ]);
        $this->runtime->removalComplete = false;

        Event::fake([RecordBroadcast::class]);

        new RemoveScheduleAction($this->runtime)->execute($schedule);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ScheduleUpdated
                && $event->id === $schedule->id,
        );
    });
});
