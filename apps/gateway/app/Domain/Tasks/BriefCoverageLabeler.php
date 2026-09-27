<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

interface BriefCoverageLabeler
{
    public function label(TaskGroup $group, TaskPullRequestHealth $merge): void;
}
