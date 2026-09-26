<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\AppInstances\AppInstanceState;
use App\Models\AppInstance;
use App\Models\TaskGroup;

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

        if (self::isTaskWorkspace($instance) && ! InstanceProvisionIntent::visitableFor($instance->app)) {
            return AppInstanceState::SourceResolved;
        }

        return AppInstanceState::Active;
    }

    /**
     * The provisioner creates a workspace only for a managed group and names it after that group.
     * An annotation links an existing_thread group to an ordinary Instance, which is not a workspace.
     */
    private static function isTaskWorkspace(AppInstance $instance): bool
    {
        return $instance->taskGroups->contains(
            static fn (TaskGroup $group): bool => $group->execution_mode === TaskExecutionMode::Managed
                && $instance->name === TaskWorkspaceName::for($group),
        );
    }
}
