<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/**
 * Starts agent conversations on the Node that owns the group's App instance.
 *
 * A no-op implementation returns null. A spawner implementation starts a fresh
 * implementer per subtask and a fresh reviewer for that subtask's first review,
 * then continues that reviewer on a later handoff of the same subtask.
 */
interface AgentSpawner
{
    public function spawnReviewer(Task $task): ?int;

    public function spawnImplementer(Task $task): ?int;

    public function requestReview(Task $task): void;
}
