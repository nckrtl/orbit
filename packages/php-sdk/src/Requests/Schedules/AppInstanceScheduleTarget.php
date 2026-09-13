<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Schedules;

final readonly class AppInstanceScheduleTarget implements ScheduleTarget
{
    public function __construct(public int $appInstanceId) {}

    /** @return array{target_type: string, target_id: int} */
    public function toRequestData(): array
    {
        return ['target_type' => 'instance', 'target_id' => $this->appInstanceId];
    }
}
