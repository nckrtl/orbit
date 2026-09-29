<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Models\Instance;
use App\Models\Schedule;

final readonly class CascadeInstanceSchedulesAction
{
    public function __construct(private RemoveScheduleAction $remove) {}

    public function execute(int $instanceId): void
    {
        Schedule::query()
            ->whereIn('target_type', Instance::morphTypes())
            ->where('target_id', $instanceId)
            ->orderBy('id')
            ->get()
            ->each(fn (Schedule $schedule) => $this->remove->execute($schedule, cascade: true));
    }
}
