<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\AppInstances\AppInstanceState;
use App\Models\AppInstance;

/**
 * The lifecycle state an Instance settles in.
 *
 * TaskWorkspaceProvisioner stops a task workspace that is not visitable after source resolution,
 * because an active Instance requires a Route. Every other Instance settles in active.
 */
final readonly class TaskWorkspaceLifecycle
{
    public static function settledState(AppInstance $instance): AppInstanceState
    {
        $instance->loadMissing(['app', 'taskGroups']);

        if ($instance->taskGroups->isNotEmpty() && ! InstanceProvisionIntent::visitableFor($instance->app)) {
            return AppInstanceState::SourceResolved;
        }

        return AppInstanceState::Active;
    }
}
