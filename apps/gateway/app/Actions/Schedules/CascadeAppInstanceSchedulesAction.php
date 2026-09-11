<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Models\AppInstance;
use App\Models\Schedule;

final readonly class CascadeAppInstanceSchedulesAction
{
    public function __construct(private RemoveScheduleAction $remove) {}

    public function execute(int $appInstanceId): void
    {
        Schedule::query()
            ->where('target_type', AppInstance::class)
            ->where('target_id', $appInstanceId)
            ->orderBy('id')
            ->get()
            ->each(fn (Schedule $schedule) => $this->remove->execute($schedule, cascade: true));
    }
}
