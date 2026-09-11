<?php

declare(strict_types=1);

use App\Actions\Schedules\ActivateScheduleAction;
use App\Actions\Schedules\AddScheduleAction;
use App\Actions\Schedules\CascadeAppInstanceSchedulesAction;
use App\Actions\Schedules\CompleteScheduleAction;
use App\Actions\Schedules\RemoveScheduleAction;
use App\Data\Schedules\AddScheduleData;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRunStatus;
use App\Domain\Schedules\ScheduleSpecificationValidator;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Schedules\ScheduleTargetUseGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Support\Str;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;
use Tests\Support\Schedules\FakeScheduleRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new FakeScheduleRuntimeManager;
    $this->targets = new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver);
    $this->addSchedule = new AddScheduleAction(
        new ScheduleSpecificationValidator,
        $this->targets,
        $this->runtime,
        app(ProcessAdmissionLock::class),
    );
    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->foreignNode = Node::query()->create([
        'name' => 'other',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.21',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.4',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
    ]);
    $this->instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/srv/apps/docs',
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
});

function lifecycle_schedule_data(AppInstance $instance, string $command = 'true', bool $start = false): AddScheduleData
{
    return new AddScheduleData(
        ScheduleTargetType::AppInstance,
        $instance->id,
        'daily-backup',
        'daily',
        $command,
        3600,
        $start,
    );
}

it('persists UUID identity and resumes an identical add without resetting timer intent', function (): void {
    $first = $this->addSchedule->execute(lifecycle_schedule_data($this->instance));
    $first['schedule']->update(['desired_timer_state' => DesiredTimerState::Enabled]);
    $second = $this->addSchedule->execute(lifecycle_schedule_data($this->instance));

    expect($first['created'])->toBeTrue()
        ->and(Str::isUuid($first['schedule']->id))->toBeTrue()
        ->and($first['schedule']->target)->toBeInstanceOf(AppInstance::class)
        ->and($first['schedule']->host_node_id)->toBe($this->node->id)
        ->and($second['created'])->toBeFalse()
        ->and($second['schedule']->id)->toBe($first['schedule']->id)
        ->and($second['schedule']->desired_timer_state)->toBe(DesiredTimerState::Enabled)
        ->and($second['schedule']->status)->toBe(LifecycleStatus::Active)
        ->and(Schedule::query()->count())->toBe(1);
});

it('returns retry conflict for a changed specification at the same target name', function (): void {
    $this->addSchedule->execute(lifecycle_schedule_data($this->instance));

    expect(fn () => $this->addSchedule->execute(lifecycle_schedule_data($this->instance, 'false')))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'schedule.retry_conflict');
});

it('keeps a failed add resumable with the same UUID', function (): void {
    $this->runtime->failure = new ScheduleOperationException(
        'install',
        ScheduleErrorCode::InstallFailed,
        'Schedule installation failed.',
    );

    try {
        $this->addSchedule->execute(lifecycle_schedule_data($this->instance));
    } catch (ScheduleOperationException) {
    }

    $failed = Schedule::query()->sole();
    expect($failed->status)->toBe(LifecycleStatus::Failed)
        ->and($failed->error_code)->toBe('schedule.install_failed');

    $this->runtime->failure = null;
    $retried = $this->addSchedule->execute(lifecycle_schedule_data($this->instance));

    expect($retried['created'])->toBeFalse()
        ->and($retried['schedule']->id)->toBe($failed->id)
        ->and($retried['schedule']->status)->toBe(LifecycleStatus::Active);
});

it('records only latest receipt metadata from the recorded host', function (): void {
    $schedule = $this->addSchedule->execute(lifecycle_schedule_data($this->instance))['schedule'];
    $before = $schedule->only(['name', 'calendar', 'command', 'timeout_seconds', 'desired_timer_state', 'status']);
    $complete = new CompleteScheduleAction;

    expect(fn () => $complete->execute($schedule->id, ScheduleRunStatus::Success, $this->foreignNode))
        ->toThrow(ResourceOperationException::class);

    $completed = $complete->execute($schedule->id, ScheduleRunStatus::Error, $this->node);

    expect($completed)->toBeInstanceOf(Schedule::class)
        ->and($completed?->last_run_status)->toBe(ScheduleRunStatus::Error)
        ->and($completed?->last_run_at)->not->toBeNull()
        ->and($completed?->only(array_keys($before)))->toBe($before);
});

it('does not update or recreate state for removing and deleted completion callbacks', function (): void {
    $schedule = $this->addSchedule->execute(lifecycle_schedule_data($this->instance))['schedule'];
    $schedule->update(['status' => LifecycleStatus::Removing]);
    $complete = new CompleteScheduleAction;

    $complete->execute($schedule->id, ScheduleRunStatus::Success, $this->node);
    expect($schedule->refresh()->last_run_at)->toBeNull();

    $id = $schedule->id;
    $schedule->delete();
    expect($complete->execute($id, ScheduleRunStatus::Success, $this->node))->toBeNull()
        ->and(Schedule::query()->whereKey($id)->exists())->toBeFalse();
});

it('keeps standalone removal resumable while a service is active', function (): void {
    $schedule = $this->addSchedule->execute(lifecycle_schedule_data($this->instance))['schedule'];
    $this->runtime->removalComplete = false;
    $remove = new RemoveScheduleAction($this->runtime);

    $remove->execute($schedule);
    expect($schedule->refresh()->status)->toBe(LifecycleStatus::Removing);

    $this->runtime->removalComplete = true;
    $remove->execute($schedule->refresh());
    expect(Schedule::query()->whereKey($schedule->id)->exists())->toBeFalse();
});

it('activates only an active AppInstance Schedule and persists desired intent after success', function (): void {
    $schedule = $this->addSchedule->execute(lifecycle_schedule_data($this->instance))['schedule'];
    $activated = new ActivateScheduleAction($this->runtime)->execute($schedule);

    expect($activated->desired_timer_state)->toBe(DesiredTimerState::Enabled)
        ->and($this->runtime->activated)->toBe([$schedule->id]);
});

it('cascades only the selected AppInstance schedules and blocks Node removal while hosted', function (): void {
    $owned = $this->addSchedule->execute(lifecycle_schedule_data($this->instance))['schedule'];
    $nodeSchedule = $this->addSchedule->execute(new AddScheduleData(
        ScheduleTargetType::Node,
        $this->node->id,
        'node-task',
        'hourly',
        'true',
    ))['schedule'];

    expect(fn () => new ScheduleTargetUseGuard()->assertNodeRemovable($this->node))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'schedule.target_in_use');

    new CascadeAppInstanceSchedulesAction(new RemoveScheduleAction($this->runtime))->execute($this->instance->id);

    expect(Schedule::query()->whereKey($owned->id)->exists())->toBeFalse()
        ->and(Schedule::query()->whereKey($nodeSchedule->id)->exists())->toBeTrue()
        ->and($this->runtime->removed)->toContain(['id' => $owned->id, 'cascade' => true]);
});
