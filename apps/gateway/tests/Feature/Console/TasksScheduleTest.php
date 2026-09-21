<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskExtensionState;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

it('registers the task tick on the schedule when tasks are enabled', function (): void {
    app(TaskExtensionState::class)->enable();
    app()->forgetInstance(Schedule::class);

    $schedule = app(Schedule::class);

    expect($schedule->events())
        ->toHaveCount(1)
        ->and($schedule->events()[0]->command)
        ->toContain('tasks:tick')
        ->and($schedule->events()[0]->filtersPass(app()))
        ->toBeTrue();
});

it('skips the task tick schedule when tasks are disabled', function (): void {
    expect(app(Schedule::class)->events())->toBeEmpty();
});

it('safely no-ops when the task tick command runs while tasks are disabled', function (): void {
    Artisan::call('tasks:tick');

    expect(Artisan::output())->toContain('Tasks extension is disabled.');
});
