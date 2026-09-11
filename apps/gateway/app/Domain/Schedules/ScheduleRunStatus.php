<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

enum ScheduleRunStatus: string
{
    case Success = 'success';
    case Error = 'error';
}
