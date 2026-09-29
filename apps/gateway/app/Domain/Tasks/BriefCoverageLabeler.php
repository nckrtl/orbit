<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

interface BriefCoverageLabeler
{
    public function label(Task $group, TaskPullRequestHealth $merge): void;
}
