<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Illuminate\Console\Scheduling\Schedule;

final readonly class TaskSchedule
{
    public function register(Schedule $schedule): void
    {
        $enabled = static fn (): bool => app(TaskExtensionState::class)->enabled();
        $schedule->command('tasks:tick')
            ->everyTenSeconds()
            ->when($enabled);
        $schedule->command('tasks:collect-t3-metrics')
            ->everyTenSeconds()
            ->withoutOverlapping()
            ->when($enabled);
        $schedule->command('problems:collect')
            ->everyTenMinutes()
            ->withoutOverlapping(15)
            ->when($enabled);
        $schedule->command('problems:file')
            ->hourly()
            ->withoutOverlapping(30)
            ->when($enabled);
    }
}
