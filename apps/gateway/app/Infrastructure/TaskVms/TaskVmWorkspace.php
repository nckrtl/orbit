<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskVm;

/**
 * The claim side of a web-lane `vm` group: the Node of its ready task VM. Until the VM is ready, the
 * claim waits with a `Task VM:` reason and tries again on the next tick. The first wait allocates the VM.
 *
 * It reads `TaskVmSettings` only here, never when the scheduler is built, so an invalid task VM value
 * makes `vm` groups wait and leaves shared groups alone.
 */
final readonly class TaskVmWorkspace
{
    /** @throws TaskCapacityException while the group's task VM is not ready */
    public function node(Task $group): Node
    {
        $vm = TaskVmPlacement::forGroup($group);
        if (! $vm instanceof TaskVm) {
            try {
                if (! app(TaskVmSettings::class)->enabled) {
                    throw new TaskCapacityException(false, 'Task VM: task VMs are not enabled on this Gateway.');
                }
                $vm = app(AllocateTaskVmAction::class)->execute($group);
            } catch (TaskVmException $exception) {
                throw new TaskCapacityException(false, "Task VM: {$exception->errorCode}: {$exception->getMessage()}");
            }
        }

        $node = $vm->node;
        if ($vm->state === TaskVmState::Ready && $node instanceof Node && $node->status === LifecycleStatus::Active) {
            return $node;
        }

        throw new TaskCapacityException(false, match ($vm->state) {
            TaskVmState::Failed => "Task VM: failed: {$vm->error_code}: {$vm->error_message}",
            TaskVmState::Destroying => 'Task VM: destroying.',
            default => 'Task VM: provisioning.',
        });
    }
}
