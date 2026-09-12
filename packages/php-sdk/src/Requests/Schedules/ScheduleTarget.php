<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Schedules;

interface ScheduleTarget
{
    /** @return array{target_type: string, target_id: int} */
    public function toRequestData(): array;
}
