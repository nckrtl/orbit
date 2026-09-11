<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Node;
use App\Models\Schedule;

interface ScheduleStateInspector
{
    public function inspect(Schedule $schedule): ScheduleInspectionData;

    /**
     * @param  list<string>  $knownIds
     * @return list<string>
     */
    public function orphanIds(Node $node, array $knownIds): array;
}
