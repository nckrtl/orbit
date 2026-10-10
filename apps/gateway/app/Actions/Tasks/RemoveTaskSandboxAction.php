<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Infrastructure\Compute\TaskSandboxLifecycle;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\DB;

/** Destroy only recorded sandbox ownership; never run guest checkout paths on the host. */
final readonly class RemoveTaskSandboxAction
{
    public function __construct(private TaskExecutionLock $execution, private TaskSandboxDrivers $drivers, private TaskSandboxLifecycle $lifecycle) {}

    public function workspace(Instance $instance, ?Task $expectedGroup = null): void
    {
        $sandbox = $instance->taskSandbox;
        if (! $sandbox instanceof TaskSandbox || ($expectedGroup !== null && $sandbox->group_id !== $expectedGroup->id)) {
            throw $this->ownership();
        }
        $this->remove($sandbox, $instance);
    }

    /** The sweep passes endedOnly; explicit completion can remove an unattached review reservation. */
    public function unattached(TaskSandbox $sandbox, bool $endedOnly = false): void
    {
        $this->remove($sandbox, null, $endedOnly);
    }

    private function remove(TaskSandbox $sandbox, ?Instance $instance, bool $endedOnly = false): void
    {
        $groupId = $sandbox->group_id;
        $this->execution->synchronized($groupId ?? 0, function () use ($sandbox, $instance, $groupId, $endedOnly): void {
            $sandbox->refresh();
            if ($sandbox->group_id !== $groupId) {
                throw $this->ownership();
            }
            if ($endedOnly && $sandbox->warm_pool && $sandbox->desired_power !== 'destroyed') {
                throw new ComputeException('compute.warm_active', 'The sandbox is an active warm reservation.');
            }
            $group = $groupId === null ? null : Task::topLevel()->find($groupId);
            if ($groupId !== null && (! $group instanceof Task || $group->task_compute !== TaskCompute::Vm || $group->execution_mode !== TaskExecutionMode::Managed)) {
                throw $this->ownership();
            }
            if ($endedOnly && $sandbox->desired_power !== 'destroyed' && $group instanceof Task && (! in_array($group->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled], true)
                || ($group->reserved_at !== null && $group->reserved_at->greaterThan(RemoveTaskWorkspaceAction::reservationCutoff())))) {
                throw new ComputeException('compute.group_active', 'The sandbox still belongs to an active task claim.');
            }
            if ($instance instanceof Instance) {
                $instance->refresh();
                $this->guardWorkspace($sandbox, $instance, $group);
            } elseif (Instance::query()->where('task_sandbox_id', $sandbox->id)->exists() || ($group instanceof Task && $group->taskable_id !== null)) {
                throw $this->ownership();
            }
            $result = $this->lifecycle->destroy($sandbox, $this->drivers->forSandbox($sandbox));
            if ($result->state !== SandboxState::Destroyed) {
                throw new ComputeException('compute.cleanup_pending', 'Sandbox destruction has not been confirmed. Orbit retains its reservation for retry.');
            }
            if ($instance instanceof Instance && Instance::query()->whereKey($instance->id)->exists()) {
                DB::transaction(function () use ($sandbox, $instance, $group): void {
                    $instance->refresh();
                    $group?->refresh();
                    $this->guardWorkspace($sandbox, $instance, $group);
                    Task::withoutGlobalScope('subtask')->where('taskable_type', $instance->getMorphClass())->where('taskable_id', $instance->id)
                        ->update(['taskable_type' => null, 'taskable_id' => null]);
                    $instance->delete();
                });
            }
        });
    }

    private function guardWorkspace(TaskSandbox $sandbox, Instance $instance, ?Task $group): void
    {
        if (! $group instanceof Task || $instance->task_sandbox_id !== $sandbox->id || $instance->project_id !== $group->project_id
            || ($group->taskable_id !== null && ($group->taskable_id !== $instance->id || $group->taskable_type !== $instance->getMorphClass()))
            || ($group->taskable_id === null && ($instance->name !== TaskWorkspaceName::for($group) || $instance->branch_override !== $instance->name))
            || $group->project->slug !== 'orbit' || $sandbox->provider !== 'incus' || $instance->node_id !== ($sandbox->spec['host_id'] ?? null)
            || Task::withoutGlobalScope('subtask')->where('taskable_type', $instance->getMorphClass())->where('taskable_id', $instance->id)
                ->whereKeyNot($group->id)->exists()) {
            throw $this->ownership();
        }
        if ($instance->routeTargets()->exists() || $instance->processes()->exists() || $instance->schedules()->exists()
            || $instance->databaseConnectionTargets()->exists() || $instance->removalMember()->exists()
            || $instance->transfers()->exists()) {
            throw new ComputeException('compute.workspace_in_use', 'The sandbox workspace has live resource references. Remove them before destroying its compute.');
        }
    }

    private function ownership(): ComputeException
    {
        return new ComputeException('compute.ownership_mismatch', 'The workspace does not have exclusive task sandbox ownership.');
    }
}
