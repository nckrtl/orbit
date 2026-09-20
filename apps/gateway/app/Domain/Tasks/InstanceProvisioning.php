<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

/**
 * Assigns the one App instance a TaskGroup shares.
 *
 * A no-op implementation returns null and leaves the group reserved.
 * A provisioner implementation creates one fresh instance per group, honors
 * visitable, places it on a Node that can run T3, and reuses that instance
 * for every subtask.
 */
interface InstanceProvisioning
{
    public function provision(InstanceProvisionIntent $intent): ?AppInstance;
}
