<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Models\Node;

interface ScheduleRuntimeAccountResolver
{
    public function resolve(Node $node, string $user): ScheduleRuntimeAccount;
}
