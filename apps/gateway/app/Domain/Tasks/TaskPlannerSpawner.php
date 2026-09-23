<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;

/**
 * ADR 0123: starts the planner thread of a Backlog group in its shared Instance.
 */
interface TaskPlannerSpawner
{
    /** The Orbit AgentThread ID of the planner, or null when the driver refused it. */
    public function spawnPlanner(TaskGroup $group): ?int;
}
