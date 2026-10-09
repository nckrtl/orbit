<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Actions\Annotations\CancelInstanceAnnotationTasksAction;
use App\Domain\Compute\ComputeException;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProjectSandboxRuntimeGuard;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Native withdrawal evidence; the owned compute driver retains and destroys guest source. */
final readonly class ProjectSandboxInstanceRemoval
{
    public function __construct(private InstanceRemovalProjector $runtime) {}

    public static function assertJournal(Instance $workspace, InstanceRemovalMember $member): void
    {
        ProjectSandboxRuntimeGuard::assertOwned($workspace);
        $sandbox = $workspace->taskSandbox;
        $operation = $member->removal;
        if ($sandbox === null || $sandbox->desired_power !== 'destroyed' || $sandbox->model_key !== null
            || $operation->inventory_digest !== self::digest($workspace)
            || $workspace->status !== InstanceState::Removing || $member->row_deleted_at !== null
            || $operation->requested_instance_id !== $workspace->id || $operation->total !== 1 || ! $operation->force
            || $operation->members()->count() !== 1 || $operation->status === InstanceRemovalStatus::Completed
            || $member->instance_id !== $workspace->id || $member->project_id !== $workspace->project_id
            || $member->node_id !== $workspace->node_id || $member->name !== $workspace->name
            || $member->environment !== 'development' || $member->source_layout !== $workspace->source_layout
            || $member->repository_identity !== $workspace->project->repository_identity
            || $member->checkout_path !== $workspace->checkout_path || $member->root !== $workspace->effectiveRoot()
            || $member->branch !== $workspace->branch || $member->starting_commit !== $workspace->starting_commit
            || $member->source_identity !== 'sandbox:'.$workspace->task_sandbox_id
            || $member->source_digest !== self::digest($workspace)
            || $member->common_repository_path !== $workspace->checkout_path || $member->linked_worktree_paths !== []) {
            throw new ComputeException('compute.ownership_mismatch', 'The Project workspace removal journal changed ownership.');
        }
    }

    /** The caller holds the group admission lock and verifies exclusive fleet ownership. */
    public function remove(Instance $workspace): void
    {
        $sandbox = $workspace->taskSandbox;
        if ($sandbox === null || $sandbox->desired_power !== 'destroyed' || $sandbox->model_key !== null) {
            throw new ComputeException('compute.cleanup_pending', 'Record destruction intent and revoke the model key before workspace removal.');
        }
        $member = DB::transaction(function () use ($workspace): InstanceRemovalMember {
            $workspace->refresh();
            $member = $workspace->removalMember()->first();
            if ($member !== null) {
                self::assertJournal($workspace, $member);

                return $member;
            }
            $operation = InstanceRemoval::query()->create([
                'id' => (string) Str::uuid(), 'requested_instance_id' => $workspace->id, 'requested_name' => $workspace->name,
                'force' => true, 'inventory_digest' => self::digest($workspace), 'total' => 1,
                'status' => InstanceRemovalStatus::Removing, 'current_step' => InstanceRemovalStep::SourcePreparation,
            ]);
            $member = $operation->members()->create([
                'position' => 0, 'instance_id' => $workspace->id, 'project_id' => $workspace->project_id,
                'node_id' => $workspace->node_id, 'route_id' => $workspace->routes()->first()?->id, 'name' => $workspace->name,
                'environment' => 'development', 'source_layout' => $workspace->source_layout,
                'repository_identity' => $workspace->project->repository_identity, 'checkout_path' => $workspace->checkout_path,
                'root' => $workspace->effectiveRoot(), 'branch' => $workspace->branch,
                'starting_commit' => $workspace->starting_commit, 'source_commit' => $workspace->starting_commit ?? '',
                'common_repository_path' => $workspace->checkout_path, 'source_identity' => 'sandbox:'.$workspace->task_sandbox_id,
                'linked_worktree_paths' => [], 'source_digest' => self::digest($workspace),
                'runtime_published' => $workspace->status === InstanceState::Active,
            ]);
            $workspace->update(['status' => InstanceState::Removing]);
            app(CancelInstanceAnnotationTasksAction::class)->execute($workspace->id);

            return $member;
        });
        $operation = $member->removal;
        foreach (InstanceRemovalStep::cases() as $step) {
            $column = match ($step) {
                InstanceRemovalStep::SourcePreparation => 'source_prepared_at',
                InstanceRemovalStep::RouteTargetClear => 'route_cleared_at',
                InstanceRemovalStep::SourceFinalization => 'source_finalized_at',
                InstanceRemovalStep::RuntimeCleanup => 'runtime_cleaned_at',
                InstanceRemovalStep::RowDeletion => 'row_deleted_at',
            };
            if ($member->{$column} !== null) {
                continue;
            }
            self::assertJournal($workspace->refresh(), $member->refresh());
            $operation->update(['status' => InstanceRemovalStatus::Removing, 'current_step' => $step, 'failed_step' => null, 'error_code' => null]);
            try {
                match ($step) {
                    InstanceRemovalStep::SourcePreparation => $member->update(['source_prepared_at' => now()]),
                    InstanceRemovalStep::RouteTargetClear => $member->update(['route_cleared_at' => now(),
                        'route_outcome' => $member->route_id === null ? 'none' : $this->runtime->clearRouteTarget($member)]),
                    InstanceRemovalStep::SourceFinalization => $member->update(['source_finalized_at' => now(),
                        'finalization_receipt' => 'retained-in-'.$member->source_identity.':'.$member->source_digest]),
                    InstanceRemovalStep::RuntimeCleanup => $this->cleanupRuntime($member),
                    InstanceRemovalStep::RowDeletion => $this->deleteRow($workspace, $member),
                };
            } catch (Throwable $exception) {
                $operation->update(['status' => InstanceRemovalStatus::Failed, 'current_step' => $step,
                    'failed_step' => $step, 'error_code' => 'instance.removal_incomplete']);
                throw $exception;
            }
            $member->refresh();
        }
    }

    private function cleanupRuntime(InstanceRemovalMember $member): void
    {
        $this->runtime->cleanupRuntime($member);
        $member->update(['runtime_cleaned_at' => now()]);
    }

    private function deleteRow(Instance $workspace, InstanceRemovalMember $member): void
    {
        DB::transaction(function () use ($workspace, $member): void {
            self::assertJournal($workspace->refresh(), $member->refresh());
            Task::withoutGlobalScope('subtask')->where('taskable_type', $workspace->getMorphClass())->where('taskable_id', $workspace->id)
                ->update(['taskable_type' => null, 'taskable_id' => null]);
            $workspace->delete();
            $member->update(['row_deleted_at' => now()]);
            $member->removal->update(['status' => InstanceRemovalStatus::Completed, 'current_step' => null, 'failed_step' => null, 'error_code' => null]);
        });
    }

    private static function digest(Instance $workspace): string
    {
        return hash('sha256', json_encode([
            'sandbox_id' => $workspace->task_sandbox_id, 'instance_id' => $workspace->id,
            'project_id' => $workspace->project_id, 'node_id' => $workspace->node_id,
            'name' => $workspace->name, 'checkout_path' => $workspace->checkout_path,
            'root' => $workspace->effectiveRoot(), 'branch' => $workspace->branch,
            'starting_commit' => $workspace->starting_commit,
        ], JSON_THROW_ON_ERROR));
    }
}
