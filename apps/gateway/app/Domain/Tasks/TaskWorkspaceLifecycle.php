<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceState;
use App\Models\Instance;
use App\Models\Task;

/**
 * The lifecycle state an Instance settles in.
 *
 * TaskWorkspaceProvisioner stops a task workspace that is not visitable after source resolution,
 * because an active Instance requires a Route. Every other Instance settles in active.
 */
final readonly class TaskWorkspaceLifecycle
{
    public static function settledState(Instance $instance): InstanceState
    {
        $instance->loadMissing(['project', 'tasks']);

        if (self::isTaskWorkspace($instance) && ! InstanceProvisionIntent::visitableFor($instance->project)) {
            return InstanceState::SourceResolved;
        }

        return InstanceState::Active;
    }

    /**
     * The provisioner creates a workspace only for a managed group and names it after that group.
     * An annotation links an existing_thread group to an ordinary Instance, which is not a workspace.
     */
    private static function isTaskWorkspace(Instance $instance): bool
    {
        return $instance->tasks->contains(
            static fn (Task $group): bool => $group->execution_mode === TaskExecutionMode::Managed
                && $instance->name === TaskWorkspaceName::for($group),
        );
    }
}
