<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

final readonly class NullAgentSpawner implements AgentSpawner
{
    public function spawnReviewer(Task $task): ?int
    {
        return null;
    }

    public function spawnImplementer(Task $task): ?int
    {
        return null;
    }

    public function requestReview(Task $task): void {}
}
