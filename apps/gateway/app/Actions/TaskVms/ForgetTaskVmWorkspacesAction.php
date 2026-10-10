<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Annotations\CancelInstanceAnnotationTasksAction;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\InstanceTransfer;
use App\Models\Process;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Removes the workspace records of a task VM whose VM is already deleted. Normal Instance removal needs SSH
 * to the checkout, which is gone with the VM. This keeps the normal removal journal, but records the source
 * steps as done and clears the Route without touching the VM Node: the projector skips that Node while the
 * task VM is `destroying`. The router withdraws the site. The Instance's Process and Schedule records go
 * with it. Every step is recorded, so a retry continues where it stopped.
 */
final readonly class ForgetTaskVmWorkspacesAction
{
    public function __construct(
        private InstanceRemovalProjector $routes,
        private CancelInstanceAnnotationTasksAction $annotations,
    ) {}

    public function execute(TaskVm $vm): void
    {
        if ($vm->state !== TaskVmState::Destroying) {
            throw new TaskVmException('task_vm.job_failed', "Task VM [{$vm->name}] must be destroying before its workspace is forgotten.", 500);
        }

        foreach (Instance::query()->with('project')->where('node_id', $vm->node_id)->orderBy('id')->get() as $instance) {
            if (in_array($instance->status, [InstanceState::Reserved, InstanceState::CheckoutPrepared], true) && ! $instance->routes()->exists()) {
                $this->deleteRow($instance, null);

                continue;
            }
            $member = $this->journal($instance, $vm);
            $this->finish($instance, $member);
        }
    }

    /** The open removal journal of the Instance, or a new one when it has none. */
    private function journal(Instance $instance, TaskVm $vm): InstanceRemovalMember
    {
        return DB::transaction(function () use ($instance, $vm): InstanceRemovalMember {
            $instance->refresh();
            $open = $instance->removalMember()->first();
            if ($open instanceof InstanceRemovalMember) {
                return $open;
            }
            $digest = hash('sha256', 'task-vm:'.$vm->id.':'.$instance->id);
            $operation = InstanceRemoval::query()->create([
                'id' => (string) Str::uuid(), 'requested_instance_id' => $instance->id, 'requested_name' => $instance->name,
                'force' => true, 'inventory_digest' => $digest, 'total' => 1,
                'status' => InstanceRemovalStatus::Removing, 'current_step' => InstanceRemovalStep::SourcePreparation,
            ]);
            $member = $operation->members()->create([
                'position' => 0, 'instance_id' => $instance->id, 'project_id' => $instance->project_id,
                'node_id' => $instance->node_id, 'route_id' => $instance->routes()->first()?->id, 'name' => $instance->name,
                'environment' => 'development', 'source_layout' => $instance->source_layout,
                'repository_identity' => $instance->project->repository_identity, 'checkout_path' => $instance->checkout_path,
                'root' => $instance->effectiveRoot(), 'branch' => $instance->branch,
                'starting_commit' => $instance->starting_commit, 'source_commit' => $instance->starting_commit ?? '',
                'common_repository_path' => $instance->checkout_path, 'source_identity' => 'task-vm:'.$vm->name,
                'linked_worktree_paths' => [], 'source_digest' => $digest,
                'runtime_published' => $instance->status === InstanceState::Active,
            ]);
            $instance->update(['status' => InstanceState::Removing]);
            $this->annotations->execute($instance->id);

            return $member;
        });
    }

    private function finish(Instance $instance, InstanceRemovalMember $member): void
    {
        $operation = $member->removal;
        foreach (InstanceRemovalStep::cases() as $step) {
            $member->refresh();
            $done = match ($step) {
                InstanceRemovalStep::SourcePreparation => $member->source_prepared_at,
                InstanceRemovalStep::RouteTargetClear => $member->route_cleared_at,
                InstanceRemovalStep::SourceFinalization => $member->source_finalized_at,
                InstanceRemovalStep::RuntimeCleanup => $member->runtime_cleaned_at,
                InstanceRemovalStep::RowDeletion => $member->row_deleted_at,
            };
            if ($done !== null) {
                continue;
            }
            $operation->update(['status' => InstanceRemovalStatus::Removing, 'current_step' => $step, 'failed_step' => null, 'error_code' => null]);
            try {
                match ($step) {
                    InstanceRemovalStep::SourcePreparation => $member->update(['source_prepared_at' => now()]),
                    InstanceRemovalStep::RouteTargetClear => $member->update([
                        'route_cleared_at' => now(),
                        'route_outcome' => $member->route_id === null ? 'none' : $this->routes->clearRouteTarget($member),
                    ]),
                    InstanceRemovalStep::SourceFinalization => $member->update(['source_finalized_at' => now(), 'finalization_receipt' => 'deleted-with-'.$member->source_identity]),
                    InstanceRemovalStep::RuntimeCleanup => $this->forgetRuntime($member),
                    InstanceRemovalStep::RowDeletion => $this->deleteRow($instance, $member),
                };
            } catch (\Throwable $exception) {
                $operation->update(['status' => InstanceRemovalStatus::Failed, 'current_step' => $step, 'failed_step' => $step, 'error_code' => 'instance.removal_incomplete']);

                throw $exception;
            }
        }
    }

    /** The VM that ran them is gone, so only the records remain. */
    private function forgetRuntime(InstanceRemovalMember $member): void
    {
        Process::query()->where('owner_type', (new Instance)->getMorphClass())->where('owner_id', $member->instance_id)->delete();
        Schedule::query()->where('target_type', (new Instance)->getMorphClass())->where('target_id', $member->instance_id)->delete();
        $member->update(['runtime_cleaned_at' => now()]);
    }

    private function deleteRow(Instance $instance, ?InstanceRemovalMember $member): void
    {
        DB::transaction(function () use ($instance, $member): void {
            if (InstanceTransfer::query()->where('instance_id', $instance->id)->open()->exists()) {
                throw new TaskVmException('task_vm.job_failed', "Instance [{$instance->id}] has an open transfer.", 409);
            }
            InstanceTransfer::query()->where('instance_id', $instance->id)->update(['instance_id' => null]);
            Task::withoutGlobalScope('subtask')->where('taskable_type', $instance->getMorphClass())->where('taskable_id', $instance->id)
                ->update(['taskable_type' => null, 'taskable_id' => null]);
            $instance->delete();
            if ($member instanceof InstanceRemovalMember) {
                $member->update(['row_deleted_at' => now()]);
                $member->removal->update(['status' => InstanceRemovalStatus::Completed, 'current_step' => null, 'failed_step' => null, 'error_code' => null]);
            }
        });
    }
}
