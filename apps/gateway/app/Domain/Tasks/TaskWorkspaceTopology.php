<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

/** The task group's Incus discovery topology, acquired and released by Orbit as the managed user. */
interface TaskWorkspaceTopology
{
    /** Acquire TASK-{group}; return true when acquired, false when already held. Throw on failure. */
    public function acquire(Instance $workspace, int $groupId): bool;

    /** Release TASK-{group} when the workspace holds one. */
    public function release(Instance $workspace, int $groupId): void;
}
