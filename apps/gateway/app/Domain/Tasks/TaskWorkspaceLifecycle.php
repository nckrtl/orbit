<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceState;
use App\Models\Instance;

/**
 * The lifecycle state an Instance settles in.
 *
 * A task workspace records whether provisioning gave it a Route. An unrouted workspace
 * settles at source_resolved. A routed workspace, and every ordinary Instance, settles
 * at active. Doctor uses that record, not the Project's current setting or slug.
 */
final readonly class TaskWorkspaceLifecycle
{
    public static function settledState(Instance $instance): InstanceState
    {
        if ($instance->task_workspace_routed === false) {
            return InstanceState::SourceResolved;
        }

        return InstanceState::Active;
    }
}
