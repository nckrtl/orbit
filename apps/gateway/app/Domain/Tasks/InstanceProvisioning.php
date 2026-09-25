<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

/**
 * Assigns the one App instance a TaskGroup shares.
 *
 * A no-op implementation returns null.
 * TaskWorkspaceProvisioner creates one fresh instance per group, honors
 * visitable, places it on an app-dev Node that supports the selected agent driver, and reuses that
 * instance for every subtask. It returns the instance the group holds, and resumes an unattached
 * `task-{group id}` instance on its own Node.
 *
 * A null result means provisioning failed. A capacity wait throws TaskCapacityException instead.
 */
interface InstanceProvisioning
{
    /** @throws TaskCapacityException when every Node that could host the group is at the task ceiling. */
    public function provision(InstanceProvisionIntent $intent): ?AppInstance;
}
