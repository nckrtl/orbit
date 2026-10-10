<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Prepares Pi on the enrolled VM, then marks the task VM `ready` for the next claim. */
final class PrepareTaskVmRuntime implements ShouldBeUnique, ShouldQueue
{
    use ProvisionsTaskVm;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 900;

    public function handle(TaskVmRuntime $runtime): void
    {
        $vm = $this->provisioning();
        if (! $vm instanceof TaskVm) {
            return;
        }
        if ($vm->node?->status !== LifecycleStatus::Active) {
            throw new TaskVmException('task_vm.not_enrolled', "Task VM [{$vm->name}] has no active Node.");
        }

        $runtime->prepare($vm);
        $vm->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null]);
    }
}
