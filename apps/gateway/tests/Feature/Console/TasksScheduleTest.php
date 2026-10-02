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
    $collect = collect($schedule->events())->first(
        static fn ($event): bool => str_contains((string) $event->command, 'problems:collect'),
    );
    $file = collect($schedule->events())->first(
        static fn ($event): bool => str_contains((string) $event->command, 'problems:file'),
    );
    expect($schedule->events())->toHaveCount(3)
        ->and(array_any($commands, static fn (string $command): bool => str_contains($command, 'tasks:tick')))->toBeTrue()
        ->and(array_any($commands, static fn (string $command): bool => str_contains($command, 'tasks:collect-t3-metrics')))->toBeFalse()
        ->and($collect)->not->toBeNull()
        ->and($collect->expression)->toBe('*/10 * * * *')
        ->and($collect->withoutOverlapping)->toBeTrue()
        ->and($collect->expiresAt)->toBe(15)
        ->and($file)->not->toBeNull()
        ->and($file->expression)->toBe('0 * * * *')
        ->and($file->withoutOverlapping)->toBeTrue()
        ->and($file->expiresAt)->toBe(30)
        ->and(array_all($schedule->events(), static fn ($event): bool => $event->filtersPass(app())))->toBeTrue();
});

it('skips the task tick schedule when tasks are disabled', function (): void {
    $schedule = task_schedule(enabled: false);

    expect($schedule->events())->toHaveCount(3)
        ->and(array_all($schedule->events(), static fn ($event): bool => $event->filtersPass(app())))->toBeFalse();
});

it('safely no-ops when the task tick command runs while tasks are disabled', function (): void {
    Artisan::call('tasks:tick');

    expect(Artisan::output())->toContain('Tasks extension is disabled.');
});
