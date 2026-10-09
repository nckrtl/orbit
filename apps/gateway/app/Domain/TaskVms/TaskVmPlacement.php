<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskVm;

/**
 * The one placement rule for task VMs: a Node with a live task VM serves only its group's
 * workspace, and a `vm` group works only in that workspace. A live task VM is any row that is
 * not Destroyed.
 */
final class TaskVmPlacement
{
    /** The live task VM that this Node is, or null for every other Node. */
    public static function forNode(Node $node): ?TaskVm
    {
        return self::forNodeId($node->id);
    }

    /** The group's live task VM, or null when it has none. */
    public static function forGroup(Task $group): ?TaskVm
    {
        return TaskVm::query()->live()->where('group_id', $group->id)->first();
    }

    /**
     * A `vm` group's workspace must be its `task-<group id>` Instance in the group's Project, on
     * the Node of its Ready task VM. Any other group's workspace must not be on a task VM Node.
     */
    public static function assertWorkspace(Task $group, Instance $workspace): void
    {
        if ($group->task_compute !== TaskCompute::Vm) {
            if (self::forNodeId($workspace->node_id) instanceof TaskVm) {
                throw new TaskVmException('task_vm.workspace_mismatch', "Task group [{$group->id}] does not own the task VM that serves its workspace.");
            }

            return;
        }

        $vm = self::forGroup($group);
        if (! $vm instanceof TaskVm || $vm->state !== TaskVmState::Ready || $vm->node_id === null
            || $workspace->node_id !== $vm->node_id || $workspace->project_id !== $group->project_id
            || $workspace->name !== TaskWorkspaceName::for($group)) {
            throw new TaskVmException('task_vm.workspace_mismatch', "Task group [{$group->id}] has no workspace on its ready task VM.");
        }
    }

    /** Whether `assertWorkspace` accepts the workspace. */
    public static function allowsWorkspace(Task $group, Instance $workspace): bool
    {
        try {
            self::assertWorkspace($group, $workspace);

            return true;
        } catch (TaskVmException) {
            return false;
        }
    }

    /** When a `vm` group owns this workspace, the workspace must be on that group's ready task VM. */
    public static function assertOwnedWorkspace(Instance $workspace): void
    {
        $group = Task::topLevel()->where('taskable_type', $workspace->getMorphClass())->where('taskable_id', $workspace->id)
            ->where('task_compute', TaskCompute::Vm->value)->first();
        if ($group instanceof Task) {
            self::assertWorkspace($group, $workspace);
        }
    }

    /** An Instance on a task VM Node must be that group's `task-<group id>` workspace in the group's Project. */
    public static function assertInstance(Instance $instance): void
    {
        $vm = self::forNodeId($instance->node_id);
        if (! $vm instanceof TaskVm) {
            return;
        }

        $group = $vm->group;
        if ($instance->project_id !== $group->project_id || $instance->name !== TaskWorkspaceName::for($group)) {
            throw new TaskVmException('task_vm.foreign_instance', "Node [{$instance->node_id}] is a task VM and serves only the workspace of task group [{$group->id}].");
        }
    }

    private static function forNodeId(int $nodeId): ?TaskVm
    {
        return TaskVm::query()->live()->where('node_id', $nodeId)->first();
    }
}
