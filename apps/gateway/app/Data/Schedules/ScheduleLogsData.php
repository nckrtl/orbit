<?php

declare(strict_types=1);

namespace App\Data\Schedules;

final readonly class ScheduleLogsData
{
    public function __construct(
        public string $output,
        public bool $truncated,
    ) {}
}
