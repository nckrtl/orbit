<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceRemovalStatus;
use App\Domain\AppInstances\AppInstanceRemovalStep;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Removal\AppInstanceRemovalException;
use App\Domain\AppInstances\Removal\AppInstanceRemovalProjector;
use App\Domain\AppInstances\Removal\AppInstanceSourceInventory;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceRemoval;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\AppInstanceRemovalMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * @mago-expect lint:too-many-methods One coordinator owns the closed removal state machine.
 * @mago-expect lint:cyclomatic-complexity The coordinator advances each durable checkpoint and refusal boundary explicitly.
 * @mago-expect lint:kan-defect Retry safety requires each accepted and resumed transition to fail closed.
 */
final readonly class RemoveAppInstanceAction
{
    public function __construct(
        private DevelopmentAppInstanceSourceRemoval $sources,
        private AppInstanceRemovalProjector $routes,
        private ManagedCheckoutOverlap $checkoutOverlap,
    ) {}

    public function execute(AppInstance $appInstance, bool $force): AppInstanceRemoval
    {
        $snapshot = $appInstance->refresh()->load(['app', 'node', 'routes.targets']);

        if ($snapshot->status === AppInstanceState::Removing) {
            return $this->resume($snapshot, $force);
        }

        return $this->advance($this->accept($snapshot, $force));
    }

    private function resume(AppInstance $appInstance, bool $force): AppInstanceRemoval
    {
        $member = AppInstanceRemovalMember::query()
            ->where('app_instance_id', $appInstance->id)
            ->whereNull('row_deleted_at')
            ->with('removal.members')
            ->first();

        if (! $member instanceof AppInstanceRemovalMember) {
            $this->conflict($appInstance);
        }

        $removal = $member->removal;

        if ($removal->requested_app_instance_id !== $appInstance->id || $removal->force !== $force) {
            $this->conflict($appInstance);
        }

        $this->revalidateUnfinishedSources($removal);

        $removal->update([
            'status' => AppInstanceRemovalStatus::Removing,
            'failed_step' => null,
            'error_code' => null,
        ]);

        return $this->advance($removal->refresh());
    }

    private function accept(AppInstance $appInstance, bool $force): AppInstanceRemoval
    {
        if ($appInstance->migration_required) {
            throw new ResourceOperationException(
                errorCode: 'instance.migration_required',
                message: "AppInstance [{$appInstance->name}] requires manual source migration.",
                status: 409,
            );
        }

        if ($appInstance->status !== AppInstanceState::Active) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "AppInstance [{$appInstance->name}] is not active.",
                status: 409,
            );
        }

        [$members, $inventories] = $this->deletionSet($appInstance, $force);
        $digest = $this->inventoryDigest($appInstance->id, $force, $inventories);

        /** @var AppInstanceRemoval $operation */
        $operation = DB::transaction(function () use (
            $appInstance,
            $force,
            $members,
            $inventories,
            $digest,
        ): AppInstanceRemoval {
            $locked = AppInstance::query()
                ->whereKey($members->pluck('id'))
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if (
                $locked->count() !== $members->count()
                || $locked->contains(
                    static fn (AppInstance $member): bool => $member->status !== AppInstanceState::Active,
                )
            ) {
                $this->conflict($appInstance);
            }

            $operation = AppInstanceRemoval::query()->create([
                'id' => (string) Str::uuid(),
                'requested_app_instance_id' => $appInstance->id,
                'requested_name' => $appInstance->name,
                'force' => $force,
                'inventory_digest' => $digest,
                'total' => $members->count(),
                'status' => AppInstanceRemovalStatus::Removing,
                'current_step' => AppInstanceRemovalStep::SourcePreparation,
            ]);

            foreach ($members as $position => $member) {
                $inventory = $inventories[$member->id];
                $route = $member->routes->sole();
                $operation
                    ->members()
                    ->create([
                        'position' => $position,
                        'app_instance_id' => $member->id,
                        'app_id' => $member->app_id,
                        'node_id' => $member->node_id,
                        'route_id' => $route->id,
                        'name' => $member->name,
                        'environment' => $member->environment,
                        'source_layout' => $inventory->layout,
                        'repository_identity' => $inventory->repositoryIdentity,
                        'checkout_path' => $inventory->checkoutPath,
                        'root' => $inventory->root,
                        'branch' => $inventory->branch,
                        'starting_commit' => $inventory->startingCommit,
                        'common_repository_path' => $inventory->commonRepositoryPath,
                        'linked_worktree_paths' => $inventory->linkedWorktreePaths,
                        'source_digest' => $inventory->digest,
                    ]);
            }

            AppInstance::query()
                ->whereKey($members->pluck('id'))
                ->update([
                    'status' => AppInstanceState::Removing->value,
                ]);

            return $operation->load('members');
        });

        return $operation;
    }

    /**
     * @return array{Collection<int, AppInstance>, array<int, AppInstanceSourceInventory>}
     */
    private function deletionSet(AppInstance $requested, bool $force): array
    {
        $requestedInventory = $this->inspect($requested, $force);
        $members = collect([$requested]);

        if ($requested->environment === 'development') {
            $registered = AppInstance::query()
                ->with(['app', 'node', 'routes.targets'])
                ->where('node_id', $requested->node_id)
                ->whereIn('checkout_path', $requestedInventory->linkedWorktreePaths)
                ->get();

            if ($registered->count() !== count($requestedInventory->linkedWorktreePaths)) {
                throw new ResourceOperationException(
                    errorCode: 'instance.remove_refused',
                    message: 'Every linked worktree must be a registered AppInstance before removal.',
                    status: 409,
                );
            }

            if ($requested->source_layout === AppInstanceSourceLayout::Checkout->value) {
                if ($registered->count() > 1 && ! $force) {
                    throw new ResourceOperationException(
                        errorCode: 'instance.remove_refused',
                        message: 'The checkout has registered linked worktrees; retry with --force.',
                        status: 409,
                    );
                }

                if ($force) {
                    $members = $registered
                        ->sortBy(static fn (AppInstance $member): string => sprintf(
                            '%d:%s',
                            $member->source_layout === AppInstanceSourceLayout::Checkout->value ? 1 : 0,
                            $member->checkout_path,
                        ))
                        ->values();
                }
            }
        }

        /** @var array<int, AppInstanceSourceInventory> $inventories */
        $inventories = [];

        foreach ($members as $member) {
            $member->loadMissing(['app', 'node', 'routes.targets']);

            if (
                $member->status === AppInstanceState::Removing
            ) {
                $this->conflict($member);
            }

            if (
                $member->status !== AppInstanceState::Active
                || $member->migration_required
                || $member->routes->count() !== 1
                || $member->routes->sole()->status !== RouteStatus::Active
            ) {
                throw new ResourceOperationException(
                    errorCode: 'instance.remove_refused',
                    message: "AppInstance [{$member->name}] is not safe to remove.",
                    status: 409,
                );
            }

            $path = StoragePath::tryParse($member->checkout_path);

            if (! $path instanceof StoragePath) {
                throw new ResourceOperationException(
                    errorCode: 'instance.checkout_path_unsafe',
                    message: "AppInstance [{$member->name}] has an unsafe checkout path.",
                    status: 409,
                );
            }

            $this->checkoutOverlap->assertAvailable(
                $member->node_id,
                $path,
                'instance.checkout_path_unsafe',
                ignoreAppInstanceId: $member->id,
            );
            $inventories[$member->id] = $member->id === $requested->id
                ? $requestedInventory
                : $this->inspect($member, $force);
        }

        if ($requested->environment === 'development') {
            foreach ($inventories as $inventory) {
                if (
                    $inventory->linkedWorktreePaths !== $requestedInventory->linkedWorktreePaths
                    || $inventory->commonRepositoryPath !== $requestedInventory->commonRepositoryPath
                ) {
                    throw new ResourceOperationException(
                        errorCode: 'instance.remove_refused',
                        message: 'The linked-worktree inventory is inconsistent.',
                        status: 409,
                    );
                }
            }
        }

        return [collect($members->all()), $inventories];
    }

    private function inspect(AppInstance $member, bool $force): AppInstanceSourceInventory
    {
        try {
            return $this->sources->inspect($member, $force);
        } catch (RuntimeConvergenceException $exception) {
            throw new ResourceOperationException(
                errorCode: $exception->errorCode,
                message: "AppInstance [{$member->name}] source removal was refused.",
                status: 409,
                previous: $exception,
            );
        }
    }

    private function advance(AppInstanceRemoval $operation): AppInstanceRemoval
    {
        $operation->load('members');

        foreach ($operation->members as $member) {
            foreach (AppInstanceRemovalStep::cases() as $step) {
                if ($this->stepComplete($member, $step)) {
                    continue;
                }

                $operation->update([
                    'status' => AppInstanceRemovalStatus::Removing,
                    'current_step' => $step,
                    'failed_step' => null,
                    'error_code' => null,
                ]);

                try {
                    $this->runStep($operation, $member, $step);
                } catch (Throwable $exception) {
                    $errorCode = $this->errorCode($exception);
                    $operation->update([
                        'status' => AppInstanceRemovalStatus::Failed,
                        'current_step' => $step,
                        'failed_step' => $step,
                        'error_code' => $errorCode,
                    ]);

                    throw new AppInstanceRemovalException(
                        errorCode: $errorCode,
                        status: $exception instanceof ResourceOperationException ? $exception->status : 502,
                        removal: $operation->refresh()->load('members'),
                        previous: $exception,
                    );
                }

                $member->refresh();
            }
        }

        $operation->update([
            'status' => AppInstanceRemovalStatus::Completed,
            'current_step' => null,
            'failed_step' => null,
            'error_code' => null,
        ]);

        return $operation->refresh()->load('members');
    }

    private function runStep(
        AppInstanceRemoval $operation,
        AppInstanceRemovalMember $member,
        AppInstanceRemovalStep $step,
    ): void {
        match ($step) {
            AppInstanceRemovalStep::SourcePreparation => $this->prepareSource($member),
            AppInstanceRemovalStep::RouteTargetClear => $this->clearRoute($member),
            AppInstanceRemovalStep::SourceFinalization => $this->finalizeSource($operation, $member),
            AppInstanceRemovalStep::RuntimeCleanup => $this->cleanupRuntime($member),
            AppInstanceRemovalStep::RowDeletion => $this->deleteRow($member),
        };
    }

    private function prepareSource(AppInstanceRemovalMember $member): void
    {
        $this->sources->prepare($member);
        $member->update(['source_prepared_at' => now()]);
    }

    private function clearRoute(AppInstanceRemovalMember $member): void
    {
        $outcome = $this->routes->clearRouteTarget($member);
        $member->update(['route_cleared_at' => now(), 'route_outcome' => $outcome]);
    }

    private function finalizeSource(
        AppInstanceRemoval $operation,
        AppInstanceRemovalMember $member,
    ): void {
        $this->revalidateUnfinishedSources($operation);
        $receipt = $this->sources->finalize($member);
        $member->update(['source_finalized_at' => now(), 'finalization_receipt' => $receipt]);
    }

    private function cleanupRuntime(AppInstanceRemovalMember $member): void
    {
        $this->routes->cleanupRuntime($member);
        $member->update(['runtime_cleaned_at' => now()]);
    }

    private function deleteRow(AppInstanceRemovalMember $member): void
    {
        DB::transaction(function () use ($member): void {
            AppInstance::query()->lockForUpdate()->findOrFail($member->app_instance_id)->delete();
            $member->update(['row_deleted_at' => now()]);
        });
    }

    private function revalidateUnfinishedSources(AppInstanceRemoval $operation): void
    {
        $members = $operation->members()->whereNull('source_finalized_at')->orderBy('position')->get();
        $finalizedPaths = $operation
            ->members()
            ->whereNotNull('source_finalized_at')
            ->pluck('checkout_path')
            ->filter(static fn (mixed $path): bool => is_string($path))
            ->map(static fn (mixed $path): string => (string) $path)
            ->all();
        /** @var array<int, string> $finalizedPaths */

        foreach ($members as $member) {
            $this->sources->revalidate($member);
            $appInstance = AppInstance::query()->find($member->app_instance_id);

            if (! $appInstance instanceof AppInstance || $member->environment !== 'development') {
                continue;
            }

            $inventory = $this->sources->inspect($appInstance, $operation->force);
            $expectedPaths = array_values(array_diff($member->linked_worktree_paths, $finalizedPaths));
            sort($expectedPaths, SORT_STRING);

            if ($inventory->linkedWorktreePaths !== $expectedPaths) {
                throw new ResourceOperationException(
                    errorCode: 'instance.removal_conflict',
                    message: 'The linked-worktree inventory changed after removal acceptance.',
                    status: 409,
                );
            }
        }
    }

    private function stepComplete(
        AppInstanceRemovalMember $member,
        AppInstanceRemovalStep $step,
    ): bool {
        return match ($step) {
            AppInstanceRemovalStep::SourcePreparation => $member->source_prepared_at !== null,
            AppInstanceRemovalStep::RouteTargetClear => $member->route_cleared_at !== null,
            AppInstanceRemovalStep::SourceFinalization => $member->source_finalized_at !== null,
            AppInstanceRemovalStep::RuntimeCleanup => $member->runtime_cleaned_at !== null,
            AppInstanceRemovalStep::RowDeletion => $member->row_deleted_at !== null,
        };
    }

    /** @param array<int, AppInstanceSourceInventory> $inventories */
    private function inventoryDigest(int $requestedId, bool $force, array $inventories): string
    {
        $records = collect($inventories)
            ->sortKeys()
            ->map(static fn (AppInstanceSourceInventory $inventory): array => [
                'id' => $inventory->appInstanceId,
                'digest' => $inventory->digest,
                'worktrees' => $inventory->linkedWorktreePaths,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'requested_id' => $requestedId,
            'force' => $force,
            'members' => $records,
        ], JSON_THROW_ON_ERROR));
    }

    private function errorCode(Throwable $exception): string
    {
        if ($exception instanceof ResourceOperationException || $exception instanceof RuntimeConvergenceException) {
            return $exception->errorCode;
        }

        return 'instance.removal_incomplete';
    }

    private function conflict(AppInstance $appInstance): never
    {
        throw new ResourceOperationException(
            errorCode: 'instance.removal_conflict',
            message: "AppInstance [{$appInstance->name}] belongs to a different removal request.",
            status: 409,
        );
    }
}
