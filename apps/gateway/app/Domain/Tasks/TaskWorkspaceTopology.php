<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

/** The task group's Incus discovery topology, acquired and released by Orbit as the managed user. */
interface TaskWorkspaceTopology
{
    /** Acquire TASK-{group} for the workspace unless it already holds one. */
    public function acquire(Instance $workspace, int $groupId): void;

    /** Release TASK-{group} when the workspace holds one. */
    public function release(Instance $workspace, int $groupId): void;
}
