<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

interface TaskBridgeWorktreeRemover
{
    /**
     * Removes the group's bridge worktree from the registered primary checkout.
     *
     * A missing bridge is success. A worktree that does not belong to this group stays.
     */
    public function remove(Instance $instance): void;
}
