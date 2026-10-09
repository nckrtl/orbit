<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\Tasks\TaskGroupStatus;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Models\TaskVm;

/**
 * Queues `DestroyTaskVm` for every task VM that is not destroyed once its group has ended, no claim of the
 * group is in flight, and its workspace is gone. A queued job for the same VM absorbs a repeat.
 */
final readonly class DestroyEndedTaskVmsAction
{
    public function execute(): int
    {
        $vms = TaskVm::query()->live()
            ->whereHas('group', static fn ($group) => $group
                ->whereIn('status', [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled])
                ->where(static fn ($claim) => $claim->whereNull('reserved_at')->orWhere('reserved_at', '<=', RemoveTaskWorkspaceAction::reservationCutoff())))
            ->whereNotExists(static fn ($instances) => $instances->from('instances')->whereColumn('instances.node_id', 'task_vms.node_id'))
            ->get(['id']);

        foreach ($vms as $vm) {
            DestroyTaskVm::dispatch($vm->id);
        }

        return $vms->count();
    }
}
