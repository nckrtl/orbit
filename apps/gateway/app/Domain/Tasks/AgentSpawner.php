<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/**
 * Starts agent conversations on the Node that owns the group's App instance.
 *
 * A no-op implementation returns null. A spawner implementation starts a fresh
 * implementer per subtask and one reviewer for the group at its first handoff,
 * then sends that reviewer each later handoff.
 */
interface AgentSpawner
{
    public function spawnReviewer(Task $task): ?int;

    public function spawnImplementer(Task $task): ?int;

    public function requestReview(Task $task): void;
}
