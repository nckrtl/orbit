<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Models\AppInstance;
use App\Models\TaskGroup;
use Carbon\CarbonInterface;

/**
 * Finds and removes a task group's workspace, including one that a claim provisioned but never attached.
 *
 * A claim can provision the `task-{group id}` Instance and then fail, or stop, before it attaches it. The
 * workspace keeps that deterministic name and branch, so every path that ends a group finds it by name
 * when the group holds no Instance.
 */
final readonly class RemoveTaskWorkspaceAction
{
    public function __construct(private AppInstanceRemover $remover) {}

    /** The attached Instance, or the group's unattached `task-{group id}` workspace. */
    public function find(TaskGroup $group): ?AppInstance
    {
        $attached = $group->taskable;

        if ($attached instanceof AppInstance) {
            return $attached;
        }

        $name = TaskWorkspaceName::for($group);

        return AppInstance::query()
            ->where('app_id', $group->app_id)
            ->where('name', $name)
            ->where('branch_override', $name)
            ->first();
    }

    /**
     * Whether a live claim still owns the group's unattached workspace. A group reserved within
     * `orbit.tasks.reserved_timeout_seconds` has a claim in flight, and that claim removes the workspace
     * when it finds the group ended. A group reserved longer than the bound has no live claim.
     */
    public function claimInFlight(TaskGroup $group): bool
    {
        return $group->status === TaskGroupStatus::Reserved
            && $group->reserved_at instanceof CarbonInterface
            && $group->reserved_at->greaterThan(self::reservationCutoff());
    }

    public static function reservationCutoff(): CarbonInterface
    {
        return now()->subSeconds((int) config('orbit.tasks.reserved_timeout_seconds'));
    }

    /** Removes the group's workspace when it has one. It returns the removed Instance. */
    public function execute(TaskGroup $group): ?AppInstance
    {
        $instance = $this->find($group);

        if ($instance instanceof AppInstance) {
            $this->remove($instance);
        }

        return $instance;
    }

    /**
     * Removal also deletes a never-active workspace's checkout from its Node. When removal refuses
     * before it starts, for example on a half-created checkout or an unreachable Node, the record of a
     * workspace that never became active and has no Route is deleted, and Doctor reports the checkout.
     */
    public function remove(AppInstance $instance): void
    {
        try {
            $this->remover->execute($instance, true);
        } catch (ResourceOperationException $exception) {
            $instance->refresh();

            $neverActive = in_array($instance->status, [
                AppInstanceState::Reserved,
                AppInstanceState::CheckoutPrepared,
                AppInstanceState::SourceResolved,
            ], true);

            if (! $neverActive || $instance->routes()->exists()) {
                throw $exception;
            }

            $instance->delete();
        }
    }
}
