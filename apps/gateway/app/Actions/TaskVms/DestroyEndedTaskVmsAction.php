<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\Tasks\TaskGroupStatus;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Models\TaskVm;
use Illuminate\Database\Query\Builder;

/**
 * Queues `DestroyTaskVm` for every task VM that is not destroyed and whose group no live claim holds:
 * - once its group has ended and its workspace is gone;
 * - or once removal of its workspace failed after its group ended or its pull request merged, because the VM
 *   may be gone. The job checks the VM.
 * The job's unique lock absorbs a repeat while one is queued or runs.
 */
final readonly class DestroyEndedTaskVmsAction
{
    public function execute(): int
    {
        $workspace = static fn (Builder $instances): Builder => $instances->from('instances')->whereColumn('instances.node_id', 'task_vms.node_id');
        $vms = TaskVm::query()->live()
            ->whereHas('group', static fn ($group) => $group
                ->where(static fn ($claim) => $claim->whereNull('reserved_at')->orWhere('reserved_at', '<=', RemoveTaskWorkspaceAction::reservationCutoff())))
            ->where(static fn ($query) => $query
                ->where(static fn ($ended) => $ended
                    ->whereHas('group', static fn ($group) => $group->whereIn('status', [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled]))
                    ->whereNotExists($workspace))
                ->orWhere(static fn ($stranded) => $stranded
                    ->whereHas('group', static fn ($group) => $group->whereIn('status', DestroyTaskVm::endedStatuses())->where(static fn ($reason) => $reason
                        ->where('assistance_reason', 'like', RemoveTaskWorkspaceAction::RemovalFailedPrefix.'%')
                        ->orWhere('assistance_reason', 'like', RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix.'%')))
                    ->whereExists($workspace)))
            ->get(['id']);

        foreach ($vms as $vm) {
            DestroyTaskVm::dispatch($vm->id);
        }

        return $vms->count();
    }
}
