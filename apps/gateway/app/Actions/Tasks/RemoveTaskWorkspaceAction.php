<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\Tasks\TaskBridgeWorktreeRemover;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Models\AppInstance;
use App\Models\TaskGroup;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Finds and removes a task group's workspace, including one that a claim provisioned but never attached.
 *
 * A claim can provision the `task-{group id}` Instance and then fail, or stop, before it attaches it. The
 * workspace keeps that deterministic name and branch, so every path that ends a group finds it by name
 * when the group holds no Instance.
 *
 * The forced remover deletes the checkout and only then the Instance row. Before that, the group's
 * bridge worktree is removed from the registered primary checkout. A refusal leaves both the checkout
 * and the bridge in place. The caller records assistance and returns the error, so the checkout stays
 * named by a record.
 */
final readonly class RemoveTaskWorkspaceAction
{
    public const string RemovalFailedPrefix = 'Workspace removal failed: ';

    public const string MergeCleanupFailedPrefix = 'Merged pull request cleanup failed: ';

    public function __construct(
        private AppInstanceRemover $remover,
        private TaskBridgeWorktreeRemover $bridges,
    ) {}

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

    /** Removes the group's bridge worktree, then the checkout. The Instance row stays when removal refuses. */
    public function remove(AppInstance $instance): void
    {
        $this->bridges->remove($instance);
        $this->remover->execute($instance, true);
    }

    /** Asks the group for assistance and keeps the checkout and Instance row for a later retry. */
    public function recordFailure(TaskGroup $group, Throwable $exception, ?string $prefix = null): void
    {
        $group->update([
            'assistance_requested' => true,
            'assistance_reason' => ($prefix ?? self::RemovalFailedPrefix).$exception->getMessage(),
        ]);
    }

    /** Clears assistance that this removal recorded, once the checkout is gone. Another cause is left alone. */
    public function clearFailure(TaskGroup $group): void
    {
        $group->refresh();
        $reason = $group->assistance_reason;

        if (! is_string($reason)) {
            return;
        }

        if (! str_starts_with($reason, self::RemovalFailedPrefix) && ! str_starts_with($reason, self::MergeCleanupFailedPrefix)) {
            return;
        }

        $group->update(['assistance_requested' => false, 'assistance_reason' => null]);
    }
}
