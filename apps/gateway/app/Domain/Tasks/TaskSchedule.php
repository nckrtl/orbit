<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Illuminate\Console\Scheduling\Schedule;

final readonly class TaskSchedule
{
    public function register(Schedule $schedule): void
    {
        $schedule->command('tasks:tick')
            ->everyTenSeconds()
            ->runInBackground()
            ->when(static fn (): bool => app(TaskExtensionState::class)->enabled());
    }
}
