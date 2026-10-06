<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Apps\AppProjectionIdentity;
use App\Domain\Instances\Apps\AppProjectionPlan;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Projects\ProjectUpdateStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppRuntimeMigration;
use App\Models\Instance;
use App\Models\InstanceAppProjection;
use App\Models\InstanceAppUpdate;
use App\Models\InstanceRename;
use App\Models\InstanceTransfer;
use App\Models\ProjectUpdate;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Internal lifecycle API. The callback keeps the existing operation locks until it returns. */
final readonly class ReserveInstanceAppProjectionsAction
{
    public function __construct(private InstanceEnvironmentOperationLock $operations) {}

    /**
     * @template T
     *
     * @param  list<AppProjectionPlan>  $plans
     * @param  Closure(list<InstanceAppProjection>): T  $operation
     * @return T
     */
    public function project(ProjectUpdate $owner, array $plans, Closure $operation): mixed
    {
        $ids = array_map(static fn (AppProjectionPlan $plan): int => $plan->instanceId, $plans);
        sort($ids);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new LogicException('An app projection set cannot contain duplicate Instances.');
        }

        return $this->locked($ids, function () use ($owner, $plans, $ids, $operation): mixed {
            $projections = DB::transaction(function () use ($owner, $plans, $ids): array {
                $parent = ProjectUpdate::query()->lockForUpdate()->findOrFail($owner->id);
                $instances = Instance::query()->where('project_id', $parent->project_id)->orderBy('id')->lockForUpdate()->get();
                if ($instances->pluck('id')->all() !== $ids) {
                    throw new ResourceOperationException('project.update_in_progress', 'The affected Instance set changed.', 409);
                }
                $existing = InstanceAppProjection::query()->where('project_update_id', $parent->id)->orderBy('instance_id')->get();
                if ($existing->isNotEmpty()) {
                    if ($existing->pluck('instance_id')->all() !== $ids) {
                        throw new LogicException('The parent operation Instance set is immutable.');
                    }

                    return array_values($existing->all());
                }
                if (in_array($parent->status, [ProjectUpdateStatus::Complete, ProjectUpdateStatus::RolledBack], true)) {
                    throw new LogicException('Cannot reserve projections for a terminal Project update.');
                }
                InstanceAppProjection::assertAvailable($ids);
                foreach ($instances as $instance) {
                    $this->assertLifecycleAvailable($instance);
                }

                return array_map(fn (AppProjectionPlan $plan): InstanceAppProjection => $this->create($plan, $parent->id, null), $plans);
            });

            return $operation($projections);
        });
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $request  Normalized override input, not environment values.
     * @param  Closure(InstanceAppUpdate, InstanceAppProjection): T  $operation
     * @return T
     */
    public function instance(Instance $instance, array $request, AppProjectionPlan $plan, Closure $operation): mixed
    {
        if ($plan->instanceId !== $instance->id) {
            throw new LogicException('The app projection plan belongs to another Instance.');
        }

        return $this->locked([$instance->id], function () use ($instance, $request, $plan, $operation): mixed {
            [$owner, $projection] = DB::transaction(function () use ($instance, $request, $plan): array {
                $current = Instance::query()->lockForUpdate()->findOrFail($instance->id);
                $fingerprint = AppProjectionIdentity::digest($request);
                $owner = InstanceAppUpdate::query()->where('instance_id', $instance->id)->whereNull('completion')->lockForUpdate()->first();
                if ($owner !== null) {
                    if ($owner->fingerprint !== $fingerprint) {
                        throw new ResourceOperationException('instance.app_update_in_progress', 'Retry the identical app override request.', 409);
                    }
                } else {
                    InstanceAppProjection::assertAvailable([$current->id]);
                    $last = InstanceAppUpdate::query()->where('instance_id', $current->id)->latest('rowid')->first();
                    if ($last !== null && $last->fingerprint === $fingerprint && $last->completion !== null) {
                        $owner = $last;
                    } else {
                        $this->assertLifecycleAvailable($current);
                        $owner = InstanceAppUpdate::query()->create([
                            'id' => (string) Str::uuid(), 'instance_id' => $current->id,
                            'fingerprint' => $fingerprint, 'request' => $request,
                            'previous_overrides' => $current->app_overrides ?? [], 'phase' => 'reserved',
                        ]);
                    }
                }
                $projection = InstanceAppProjection::query()->where('instance_app_update_id', $owner->id)->first();

                return [$owner, $projection ?? $this->create($plan, null, $owner->id)];
            });

            return $operation($owner, $projection);
        });
    }

    private function create(AppProjectionPlan $plan, ?int $projectUpdateId, ?string $instanceAppUpdateId): InstanceAppProjection
    {
        $instance = Instance::query()->findOrFail($plan->instanceId);
        if ($instance->node_id !== $plan->nodeId) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance placement changed.', 409);
        }

        return InstanceAppProjection::query()->create([
            'id' => (string) Str::uuid(), 'instance_id' => $plan->instanceId,
            'active_instance_id' => $plan->instanceId, 'node_id' => $plan->nodeId,
            'project_update_id' => $projectUpdateId, 'instance_app_update_id' => $instanceAppUpdateId,
            'plan' => $plan->evidence(), 'plan_digest' => AppProjectionIdentity::digest($plan->evidence()),
            'render_side' => 'before',
        ]);
    }

    private function assertLifecycleAvailable(Instance $instance): void
    {
        InstanceRename::assertAvailable([$instance->id]);
        AppRuntimeMigration::assertInstanceAvailable($instance);
        if ($instance->removalMember()->exists()
            || InstanceTransfer::query()->where('instance_id', $instance->id)->whereNull('completed_at')->exists()
            || $instance->clone_candidate_id !== null && $instance->clone_completed_at === null) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'Finish the recorded Instance lifecycle operation.', 409);
        }
    }

    /**
     * @template T
     *
     * @param  list<int>  $ids
     * @param  Closure(): T  $operation
     * @return T
     */
    private function locked(array $ids, Closure $operation): mixed
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('App reservation must commit before admitting lifecycle callbacks.');
        }

        try {
            return $this->operations->run($ids, $operation);
        } catch (ResourceOperationException $exception) {
            if (in_array($exception->errorCode, ['env.operation_busy', 'process.operation_busy'], true)) {
                throw new ResourceOperationException('instance.lifecycle_busy', 'Another Instance lifecycle operation is active.', 409, previous: $exception);
            }
            throw $exception;
        }
    }
}
