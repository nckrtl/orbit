<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use App\Models\TaskGroup;

/**
 * Starts T3 agents on the Node that owns the group's App instance.
 *
 * A no-op implementation returns null. A spawner implementation starts one
 * long-lived reviewer for the group and a fresh implementer per subtask, then
 * hands settled subtasks to the reviewer and records the sign-off commit.
 */
interface AgentSpawner
{
    public function spawnReviewer(TaskGroup $group): ?string;

    public function spawnImplementer(Task $task): ?string;

    public function requestReview(Task $task): void;

    public function signOff(Task $task): ?string;
}
