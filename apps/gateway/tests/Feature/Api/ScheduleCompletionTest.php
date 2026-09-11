<?php

declare(strict_types=1);

use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRunStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Support\Facades\Route;

it('accepts one private completion from the recorded active peer without Activity', function (): void {
    $host = schedule_completion_node('host', '10.44.0.3');
    $schedule = schedule_completion_record($host);
    $activities = Activity::query()->count();

    $this->withServerVariables(['REMOTE_ADDR' => $host->wireguard_ip])
        ->postJson('/api/v1/schedules/'.$schedule->id.'/complete', ['status' => 'success'])
        ->assertNoContent();

    expect($schedule->refresh()->last_run_status)->toBe(ScheduleRunStatus::Success)
        ->and($schedule->last_run_at)->not->toBeNull()
        ->and(Activity::query()->count())->toBe($activities);
});

it('rejects a completion from another active Node and rejects unknown input', function (): void {
    $host = schedule_completion_node('host', '10.44.0.3');
    $other = schedule_completion_node('other', '10.44.0.4');
    $schedule = schedule_completion_record($host);

    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip])
        ->postJson('/api/v1/schedules/'.$schedule->id.'/complete', ['status' => 'error'])
        ->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $host->wireguard_ip])
        ->postJson('/api/v1/schedules/'.$schedule->id.'/complete', ['status' => 'success', 'extra' => true])
        ->assertUnprocessable();

    expect($schedule->refresh()->last_run_at)->toBeNull();
});

it('returns 409 for an unavailable Schedule target', function (): void {
    Route::get('schedule-target-unavailable-test', static function (): never {
        throw new ScheduleOperationException(
            'resolve-release',
            ScheduleErrorCode::TargetUnavailable,
            'The Schedule target is unavailable.',
        );
    });

    $this->getJson('/schedule-target-unavailable-test')
        ->assertConflict()
        ->assertJsonPath('error.code', 'schedule.target_unavailable')
        ->assertJsonPath('error.details.step', 'resolve-release');
});

function schedule_completion_node(string $name, string $ip): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.random_int(10, 200),
        'user' => 'orbit',
        'wireguard_ip' => $ip,
    ]);
}

function schedule_completion_record(Node $host): Schedule
{
    return Schedule::query()->create([
        'target_type' => Node::class,
        'target_id' => $host->id,
        'host_node_id' => $host->id,
        'name' => 'daily',
        'calendar' => 'daily',
        'command' => 'true',
        'timeout_seconds' => 3600,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Active,
    ]);
}
