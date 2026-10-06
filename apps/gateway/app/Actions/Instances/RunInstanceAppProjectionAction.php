<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Apps\AppProjectionIdentity;
use App\Domain\Instances\Apps\AppProjectionReceipt;
use App\Domain\Instances\Apps\AppProjectionStepAdapter;
use App\Domain\Instances\Apps\AppProjectionStepPlan;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Projects\ProjectUpdateStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\InstanceAppProjection;
use App\Models\InstanceAppProjectionStep;
use App\Models\InstanceAppUpdate;
use App\Models\ProjectUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final readonly class RunInstanceAppProjectionAction
{
    public function __construct(private InstanceEnvironmentOperationLock $operations) {}

    public function recoveryDirection(InstanceAppProjection $projection): string
    {
        return $this->operations->run([$projection->instance_id], function () use ($projection): string {
            $projection->refresh();
            $forward = $projection->project_update_id !== null
                ? in_array(ProjectUpdate::query()->findOrFail($projection->project_update_id)->status, [ProjectUpdateStatus::Publishing, ProjectUpdateStatus::CleaningUp, ProjectUpdateStatus::Complete], true)
                : InstanceAppUpdate::query()->findOrFail($projection->instance_app_update_id)->published_at !== null;
            $direction = $forward ? 'forward' : 'restore';
            $projection->update(['recovery_direction' => $direction]);

            return $direction;
        });
    }

    public function step(InstanceAppProjection $projection, AppProjectionStepPlan $plan, AppProjectionStepAdapter $adapter): AppProjectionReceipt
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('Remote app projection work cannot run inside a database transaction.');
        }

        return $this->operations->run([$projection->instance_id], function () use ($projection, $plan, $adapter): AppProjectionReceipt {
            $projection->refresh();
            if ($projection->completion !== null) {
                throw new LogicException('A completed app projection cannot run steps.');
            }
            $direction = $this->recoveryDirection($projection);
            if ($plan->phase === 'restore' && $direction !== 'restore' || $plan->phase !== 'restore' && $direction === 'forward' && $plan->phase !== 'cleanup'
                || $plan->phase === 'cleanup' && $direction !== 'forward') {
                throw new LogicException('The step conflicts with the parent publication boundary.');
            }
            [$step, $created] = DB::transaction(function () use ($projection, $plan): array {
                $existing = InstanceAppProjectionStep::query()->where('instance_app_projection_id', $projection->id)->where('step_key', $plan->key)->first();
                $intent = [...$plan->evidence(), 'instance_id' => $projection->instance_id, 'node_id' => $projection->node_id,
                    'project_update_id' => $projection->project_update_id, 'instance_app_update_id' => $projection->instance_app_update_id];
                if ($existing !== null) {
                    if ($existing->sequence !== $plan->sequence || AppProjectionIdentity::digest($existing->intent) !== AppProjectionIdentity::digest($intent)) {
                        throw new LogicException('A step retry cannot change its intent.');
                    }

                    return [$existing, false];
                }
                if ($plan->phase === 'prepare' && InstanceAppProjectionStep::query()->where('instance_app_projection_id', $projection->id)
                    ->where(static fn ($query) => $query->where('status', '!=', 'complete')->orWhereNotNull('error_code')->orWhere('intent->phase', 'restore'))->exists()) {
                    throw new ResourceOperationException('app.projection_recovery_required', 'Recover the recorded app projection before preparing new steps.', 409);
                }
                $step = InstanceAppProjectionStep::query()->create([
                    'id' => (string) Str::uuid(), 'instance_app_projection_id' => $projection->id,
                    'step_key' => $plan->key, 'sequence' => $plan->sequence, 'plan_digest' => $projection->plan_digest,
                    'intent' => $intent, 'receipt_id' => (string) Str::uuid(), 'status' => 'intended',
                ]);

                return [$step, true];
            });
            // The intent transaction has committed before either remote callback is admitted.
            if (DB::transactionLevel() > 0) {
                throw new LogicException('Remote app projection work cannot run inside a database transaction.');
            }
            try {
                $receipt = $created ? $adapter->mutate($step) : $adapter->recover($step);
                if ($receipt === null || ! $receipt->matches($step)
                    || $step->status === 'complete' && $step->receipt !== $receipt->evidence()) {
                    throw new ResourceOperationException('app.projection_receipt_conflict', 'Protected app projection evidence is missing, damaged or foreign.', 409);
                }
                $step->update(['status' => 'complete', 'receipt' => $receipt->evidence(), 'error_code' => null]);

                return $receipt;
            } catch (Throwable $exception) {
                $step->update(['error_code' => $exception instanceof ResourceOperationException ? $exception->errorCode : 'instance.app_update_failed']);
                throw $exception;
            }
        });
    }

    /**
     * Parent calls this after restoration or forward cleanup.
     *
     * @param  array<string, mixed>  $result
     */
    public function complete(ProjectUpdate|InstanceAppUpdate $owner, array $result): void
    {
        $column = $owner instanceof ProjectUpdate ? 'project_update_id' : 'instance_app_update_id';
        $ids = array_values(InstanceAppProjection::query()->where($column, $owner->id)->orderBy('instance_id')->pluck('instance_id')->map(static fn (mixed $id): int => StoredInteger::from($id))->all());
        $this->operations->run($ids, function () use ($owner, $column, $result): void {
            DB::transaction(function () use ($owner, $column, $result): void {
                $projections = InstanceAppProjection::query()->where($column, $owner->id)->lockForUpdate()->get();
                if ($projections->isEmpty()) {
                    throw new LogicException('Completion requires the recorded projection set.');
                }
                foreach ($projections as $projection) {
                    if ($projection->completion !== null) {
                        if ($projection->completion !== $result) {
                            throw new LogicException('A completion receipt is immutable.');
                        }

                        continue;
                    }
                    $direction = $this->recoveryDirection($projection);
                    $steps = InstanceAppProjectionStep::query()->where('instance_app_projection_id', $projection->id)->get();
                    foreach ($steps as $step) {
                        $restored = $direction === 'restore' && ($step->intent['phase'] ?? null) === 'prepare'
                            && $steps->contains(static fn (InstanceAppProjectionStep $restore): bool => $restore->status === 'complete' && $restore->error_code === null
                                && ($restore->intent['phase'] ?? null) === 'restore' && is_array($restore->intent['targets'] ?? null)
                                && ($restore->intent['targets']['restores_step_id'] ?? null) === $step->id);
                        if ($direction === 'restore' && ($step->intent['phase'] ?? null) === 'prepare' && ! $restored
                            || ($step->status !== 'complete' || $step->error_code !== null) && ! $restored) {
                            throw new ResourceOperationException('app.projection_incomplete', 'Finish the recorded steps before releasing app ownership.', 409);
                        }
                    }
                    $projection->update(['completion' => $result, 'active_instance_id' => null]);
                }
                if ($owner instanceof InstanceAppUpdate) {
                    $owner->refresh()->update(['completion' => $result, 'phase' => $owner->published_at === null ? 'rolled_back' : 'complete']);
                } else {
                    $owner->refresh()->update(['status' => $owner->status->recoversForward() || $owner->status === ProjectUpdateStatus::Complete ? ProjectUpdateStatus::Complete : ProjectUpdateStatus::RolledBack]);
                }
            });
        });
    }
}
