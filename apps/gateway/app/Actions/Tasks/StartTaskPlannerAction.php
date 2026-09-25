<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskPlannerMcp;
use App\Domain\Tasks\TaskPlannerSpawner;
use App\Models\AppInstance;
use App\Models\TaskGroup;

/**
 * ADR 0124: gives a new Backlog group its shared Instance and starts its planner thread.
 */
final readonly class StartTaskPlannerAction
{
    public function __construct(
        private InstanceProvisioning $provisioning,
        private TaskPlannerSpawner $planners,
        private TaskPlannerMcp $mcp,
        private CancelTaskGroupAction $cancel,
    ) {}

    /** A group whose Instance or planner cannot start is removed, so create stores no group. */
    public function execute(TaskGroup $group): TaskGroup
    {
        try {
            $instance = $this->provisioning->provision(InstanceProvisionIntent::for($group, selfAccess: true));
        } catch (TaskCapacityException) {
            $instance = null;
        }

        if (! $instance instanceof AppInstance) {
            $group->delete();

            throw TaskGroupGuard::plannerNodeUnavailable();
        }

        $group->taskable()->associate($instance);
        $group->save();

        $thread = $this->mcp->install($instance)
            ? $this->planners->spawnPlanner($group->refresh()->load(['app', 'taskable']))
            : null;

        if ($thread === null) {
            $this->cancel->execute($group);
            $group->delete();

            throw TaskGroupGuard::plannerUnavailable();
        }

        $group->update(['reviewer_agent_thread_id' => $thread]);

        return $group;
    }
}
