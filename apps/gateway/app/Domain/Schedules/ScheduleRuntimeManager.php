<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Data\Schedules\ScheduleLogsData;
use App\Models\Schedule;

interface ScheduleRuntimeManager
{
    public function install(Schedule $schedule): void;

    public function activate(Schedule $schedule): void;

    public function run(Schedule $schedule): void;

    public function logs(Schedule $schedule, int $lines): ScheduleLogsData;

    /** Returns false only when standalone removal must wait for an active command. */
    public function remove(Schedule $schedule, bool $cascade): bool;
}
