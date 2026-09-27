<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

function task_schedule(bool $enabled): Schedule
{
    $extension = app(TaskExtensionState::class);
    $enabled ? $extension->enable() : $extension->disable();

    $schedule = new Schedule;
    app(TaskSchedule::class)->register($schedule);

    return $schedule;
}

it('registers the task tick on the schedule when tasks are enabled', function (): void {
    $schedule = task_schedule(enabled: true);

    $commands = array_map(static fn ($event): string => $event->command, $schedule->events());
    expect($schedule->events())->toHaveCount(2)
        ->and(array_any($commands, static fn (string $command): bool => str_contains($command, 'tasks:tick')))->toBeTrue()
        ->and(array_any($commands, static fn (string $command): bool => str_contains($command, 'tasks:collect-t3-metrics')))->toBeTrue()
        ->and($schedule->events()[0]->filtersPass(app()))->toBeTrue()
        ->and($schedule->events()[1]->filtersPass(app()))->toBeTrue();
});

it('skips the task tick schedule when tasks are disabled', function (): void {
    $schedule = task_schedule(enabled: false);

    expect($schedule->events())
        ->toHaveCount(2)
        ->and($schedule->events()[0]->filtersPass(app()))
        ->toBeFalse()
        ->and($schedule->events()[1]->filtersPass(app()))
        ->toBeFalse();
});

it('safely no-ops when the task tick command runs while tasks are disabled', function (): void {
    Artisan::call('tasks:tick');

    expect(Artisan::output())->toContain('Tasks extension is disabled.');
});
