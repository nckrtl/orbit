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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR 0124: gives a new Backlog group its shared Instance and starts its planner thread.
 */
final readonly class StartTaskPlannerAction
{
    public function __construct(
        private InstanceProvisioning $provisioning,
        private TaskPlannerSpawner $planners,
        private TaskPlannerMcp $mcp,
        private RemoveTaskWorkspaceAction $workspaces,
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
            $this->discardUnstartedPlanner($group);

            throw TaskGroupGuard::plannerUnavailable();
        }

        $group->update(['reviewer_agent_thread_id' => $thread]);

        return $group;
    }

    /** ADR 0124: drop the group. A removal failure leaves the Instance row and is logged. */
    private function discardUnstartedPlanner(TaskGroup $group): void
    {
        $instance = $group->taskable;

        if ($instance instanceof AppInstance) {
            try {
                $this->workspaces->remove($instance);
            } catch (Throwable $exception) {
                Log::error('The planner workspace could not be removed after the planner failed to start.', [
                    'task_group_id' => $group->id,
                    'app_instance_id' => $instance->id,
                    'exception' => $exception::class,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        $group->delete();
    }
}
