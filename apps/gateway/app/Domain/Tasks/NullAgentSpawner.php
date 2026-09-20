<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use App\Models\TaskGroup;

final readonly class NullAgentSpawner implements AgentSpawner
{
    public function spawnReviewer(TaskGroup $group): ?string
    {
        return null;
    }

    public function spawnImplementer(Task $task): ?string
    {
        return null;
    }
}
