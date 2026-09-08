<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceRemovalStatus;
use App\Domain\AppInstances\AppInstanceRemovalStep;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Removal\AppInstanceRemovalException;
use App\Domain\AppInstances\Removal\AppInstanceRemovalProjector;
use App\Domain\AppInstances\Removal\AppInstanceSourceInventory;
use App\Domain\AppInstances\Removal\AppInstanceSourceRevalidationExpectation;
use App\Domain\AppInstances\Removal\AppInstanceSourceRevalidationState;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceFinalizer;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceRemoval;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\AppInstanceRemovalMember;
use App\Models\Route;
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
        private DevelopmentAppInstanceSourceRemoval $sourceInspector,
        private DevelopmentAppInstanceSourceFinalizer $sourceFinalizer,
        private AppInstanceRemovalProjector $routes,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private AppDevSourceOperationLock $sourceLock,
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

        try {
            $this->revalidateUnfinishedSource($removal);
        } catch (Throwable $exception) {
            $this->fail($removal, $this->firstIncompleteStep($removal), $exception, revalidation: true);
        }

        $removal->update([
            'status' => AppInstanceRemovalStatus::Removing,
            'failed_step' => null,
            'error_code' => null,
        ]);

        return $this->advance($removal->refresh());
    }

    private function accept(AppInstance $appInstance, bool $force): AppInstanceRemoval
    {
        $this->assertSupported($appInstance);

        return $this->sourceLock->synchronized(
            $appInstance->node_id,
            fn (): AppInstanceRemoval => $this->acceptLocked($appInstance, $force),
        );
    }

    private function acceptLocked(AppInstance $appInstance, bool $force): AppInstanceRemoval
    {
        $snapshot = $appInstance->refresh()->load(['app', 'node', 'routes.targets']);
        $this->assertSupported($snapshot);
        [$members, $inventories] = $this->deletionSet($snapshot, $force);
        $digest = $this->inventoryDigest($snapshot->id, $force, $inventories);

        /** @var AppInstanceRemoval $operation */
        $operation = DB::transaction(function () use (
            $snapshot,
            $force,
            $members,
            $inventories,
            $digest,
        ): AppInstanceRemoval {
            $lockedMembers = AppInstance::query()
                ->whereKey($members->pluck('id'))
                ->lockForUpdate()
                ->orderBy('id')
                ->get()
                ->keyBy('id');
            $routeIds = $members->map(static fn (AppInstance $member): int => $member->routes->sole()->id);
            $lockedRoutes = Route::query()
                ->with('targets')
                ->whereKey($routeIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedMembers->count() !== $members->count() || $lockedRoutes->count() !== $members->count()) {
                $this->conflict($snapshot);
            }

            foreach ($members as $member) {
                $lockedMember = $lockedMembers->get($member->id);
                $route = $member->routes->sole();
                $lockedRoute = $lockedRoutes->get($route->id);

                if (
                    ! $lockedMember instanceof AppInstance
                    || ! $lockedRoute instanceof Route
                    || $lockedMember->status !== AppInstanceState::Active
                    || $lockedMember->migration_required
                    || $lockedMember->app_id !== $member->app_id
                    || $lockedMember->node_id !== $member->node_id
                    || $lockedMember->checkout_path !== $member->checkout_path
                    || $lockedMember->source_layout !== $member->source_layout
                    || $lockedRoute->status !== RouteStatus::Active
                    || $lockedRoute->targets->count() !== 1
                    || $lockedRoute->targets->sole()->app_instance_id !== $lockedMember->id
                ) {
                    $this->conflict($snapshot);
                }
            }

            $operation = AppInstanceRemoval::query()->create([
                'id' => (string) Str::uuid(),
                'requested_app_instance_id' => $snapshot->id,
                'requested_name' => $snapshot->name,
                'force' => $force,
                'inventory_digest' => $digest,
                'total' => $members->count(),
                'status' => AppInstanceRemovalStatus::Removing,
                'current_step' => AppInstanceRemovalStep::SourcePreparation,
            ]);

            foreach ($members as $position => $member) {
                $inventory = $inventories[$member->id];
                $operation
                    ->members()
                    ->create([
                        'position' => $position,
                        'app_instance_id' => $member->id,
                        'app_id' => $member->app_id,
                        'node_id' => $member->node_id,
                        'route_id' => $member->routes->sole()->id,
                        'name' => $member->name,
                        'environment' => $member->environment,
                        'source_layout' => $inventory->layout,
                        'repository_identity' => $inventory->repositoryIdentity,
                        'checkout_path' => $inventory->checkoutPath,
                        'root' => $member->effectiveRoot(),
                        'branch' => $inventory->branch,
                        'starting_commit' => $inventory->startingCommit,
                        'common_repository_path' => $inventory->commonRepositoryPath,
                        'source_identity' => $inventory->sourceIdentity,
                        'linked_worktree_paths' => $inventory->linkedWorktreePaths,
                        'source_digest' => $inventory->digest,
                    ]);
            }

            AppInstance::query()
                ->whereKey($members->pluck('id'))
                ->update(['status' => AppInstanceState::Removing->value]);

            return $operation->load('members');
        });

        return $operation;
    }

    private function assertSupported(AppInstance $appInstance): void
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

        if ($appInstance->environment !== 'development') {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: 'Production AppInstance removal is not available.',
                status: 409,
            );
        }

        if (! in_array(
            $appInstance->source_layout,
            [AppInstanceSourceLayout::Checkout->value, AppInstanceSourceLayout::Worktree->value],
            true,
        )) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: 'AppInstance source layout is not removable.',
                status: 409,
            );
        }
    }

    /**
     * @return array{Collection<int, AppInstance>, array<int, AppInstanceSourceInventory>}
     */
    private function deletionSet(AppInstance $requested, bool $force): array
    {
        $this->assertMemberPathAvailable($requested);
        $requestedInventory = $this->inspect(
            $requested,
            $force,
            inspectContent: $requested->source_layout !== AppInstanceSourceLayout::Checkout->value,
        );
        $this->assertMemberPathAvailable($requested);
        /** @var Collection<int, AppInstance> $members */
        $members = collect([$requested]);

        if ($requested->source_layout === AppInstanceSourceLayout::Checkout->value) {
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

            if ($registered->count() > 1 && ! $force) {
                throw new ResourceOperationException(
                    errorCode: 'instance.remove_refused',
                    message: 'The checkout has registered linked worktrees; retry with --force.',
                    status: 409,
                );
            }

            if (! $force) {
                $contentInventory = $this->inspect($requested, false);
                $this->assertMemberPathAvailable($requested);

                if (
                    $contentInventory->digest !== $requestedInventory->digest
                    || $contentInventory->worktreeInventory !== $requestedInventory->worktreeInventory
                ) {
                    throw new ResourceOperationException(
                        errorCode: 'instance.remove_refused',
                        message: 'The linked-worktree inventory is inconsistent.',
                        status: 409,
                    );
                }

                $requestedInventory = $contentInventory;
            }

            if ($force) {
                $members = collect(
                    $registered
                        ->sortBy(static fn (AppInstance $member): string => sprintf(
                            '%d:%s',
                            $member->source_layout === AppInstanceSourceLayout::Checkout->value ? 1 : 0,
                            $member->checkout_path,
                        ))
                        ->values()
                        ->all(),
                );
            }
        }

        /** @var array<int, AppInstanceSourceInventory> $inventories */
        $inventories = [];

        foreach ($members as $member) {
            $member->loadMissing(['app', 'node', 'routes.targets']);
            $this->assertSupported($member);

            if (
                $member->node_id !== $requested->node_id
                || $member->app_id !== $requested->app_id
                || $member->app->repository_identity !== $requested->app->repository_identity
            ) {
                throw new ResourceOperationException(
                    errorCode: 'instance.remove_refused',
                    message: "AppInstance [{$member->name}] is not owned by the requested source set.",
                    status: 409,
                );
            }

            $this->route($member);
            $this->assertMemberPathAvailable($member);
            $inventory = $member->id === $requested->id
                ? $requestedInventory
                : $this->inspect($member, $force);
            $this->assertMemberPathAvailable($member);

            if (
                $requested->source_layout === AppInstanceSourceLayout::Checkout->value
                && ($inventory->linkedWorktreePaths !== $requestedInventory->linkedWorktreePaths
                || $inventory->commonRepositoryPath !== $requestedInventory->commonRepositoryPath)
            ) {
                throw new ResourceOperationException(
                    errorCode: 'instance.remove_refused',
                    message: 'The linked-worktree inventory is inconsistent.',
                    status: 409,
                );
            }

            $inventories[$member->id] = $inventory;
        }

        if (
            $requested->source_layout === AppInstanceSourceLayout::Checkout->value
            && $members->where('source_layout', AppInstanceSourceLayout::Checkout->value)->count() !== 1
        ) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: 'The linked-worktree inventory does not identify one common checkout.',
                status: 409,
            );
        }

        return [$members, $inventories];
    }

    private function assertMemberPathAvailable(AppInstance $member): void
    {
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
    }

    private function route(AppInstance $appInstance): Route
    {
        if ($appInstance->routes->count() !== 1) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "AppInstance [{$appInstance->name}] does not have one removable Route.",
                status: 409,
            );
        }

        $route = $appInstance->routes->sole();

        if (
            $route->status !== RouteStatus::Active
            || $route->targets->count() !== 1
            || $route->targets->sole()->app_instance_id !== $appInstance->id
        ) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "AppInstance [{$appInstance->name}] Route is not safe to remove.",
                status: 409,
            );
        }

        return $route;
    }

    private function inspect(
        AppInstance $appInstance,
        bool $force,
        bool $inspectContent = true,
    ): AppInstanceSourceInventory {
        try {
            return $this->sourceInspector->inspect($appInstance, $force, $inspectContent);
        } catch (RuntimeConvergenceException $exception) {
            throw new ResourceOperationException(
                errorCode: $exception->errorCode,
                message: "AppInstance [{$appInstance->name}] source removal was refused.",
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
                    $this->fail($operation, $step, $exception);
                }

                $member->refresh();
            }
        }

        return $operation->refresh()->load('members');
    }

    private function runStep(
        AppInstanceRemoval $operation,
        AppInstanceRemovalMember $member,
        AppInstanceRemovalStep $step,
    ): void {
        match ($step) {
            AppInstanceRemovalStep::SourcePreparation => $this->prepareSource($operation, $member),
            AppInstanceRemovalStep::RouteTargetClear => $this->clearRoute($member),
            AppInstanceRemovalStep::SourceFinalization => $this->finalizeSource($operation, $member),
            AppInstanceRemovalStep::RuntimeCleanup => $this->cleanupRuntime($member),
            AppInstanceRemovalStep::RowDeletion => $this->deleteRow($operation, $member),
        };
    }

    private function prepareSource(
        AppInstanceRemoval $operation,
        AppInstanceRemovalMember $member,
    ): void {
        $expectation = $this->expectationFor(
            $member,
            $operation->members()->orderBy('position')->get(),
            [],
        );
        $this->sourceFinalizer->prepare($member, $expectation);
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
        $expectation = $this->revalidateUnfinishedSources($operation, $member);
        $receipt = $this->sourceFinalizer->finalize($member, $expectation);
        $member->update(['source_finalized_at' => now(), 'finalization_receipt' => $receipt]);
    }

    private function cleanupRuntime(AppInstanceRemovalMember $member): void
    {
        $this->routes->cleanupRuntime($member);
        $member->update(['runtime_cleaned_at' => now()]);
    }

    private function deleteRow(AppInstanceRemoval $operation, AppInstanceRemovalMember $member): void
    {
        DB::transaction(function () use ($operation, $member): void {
            $lockedOperation = AppInstanceRemoval::query()->lockForUpdate()->findOrFail($operation->id);
            $lockedMember = $lockedOperation->members()->lockForUpdate()->findOrFail($member->id);
            AppInstance::query()->lockForUpdate()->findOrFail($member->app_instance_id)->delete();
            $lockedMember->update(['row_deleted_at' => now()]);

            if ($lockedOperation->members()->whereNull('row_deleted_at')->exists()) {
                return;
            }

            $lockedOperation->update([
                'status' => AppInstanceRemovalStatus::Completed,
                'current_step' => null,
                'failed_step' => null,
                'error_code' => null,
            ]);
        });
    }

    private function revalidateUnfinishedSource(AppInstanceRemoval $operation): void
    {
        $member = $operation->members()->whereNull('row_deleted_at')->orderBy('position')->first();

        if (! $member instanceof AppInstanceRemovalMember) {
            return;
        }

        $this->revalidateUnfinishedSources($operation, $member);
    }

    private function revalidateUnfinishedSources(
        AppInstanceRemoval $operation,
        AppInstanceRemovalMember $current,
    ): AppInstanceSourceRevalidationExpectation {
        $members = $operation->members()->orderBy('position')->get();
        $developmentNodeIds = $members
            ->where('environment', 'development')
            ->pluck('node_id')
            ->unique()
            ->values();

        if ($developmentNodeIds->count() !== 1) {
            throw new ResourceOperationException(
                errorCode: 'instance.removal_conflict',
                message: 'The recorded removal does not have one development source lock.',
                status: 409,
            );
        }

        return $this->sourceLock->synchronized(
            (int) $developmentNodeIds->sole(),
            fn (): AppInstanceSourceRevalidationExpectation => $this->revalidateUnfinishedSourcesLocked(
                $members,
                $current,
            ),
        );
    }

    /**
     * @param Collection<int, AppInstanceRemovalMember> $members
     */
    private function revalidateUnfinishedSourcesLocked(
        Collection $members,
        AppInstanceRemovalMember $current,
    ): AppInstanceSourceRevalidationExpectation {
        /** @var array<int, AppInstanceSourceRevalidationState> $states */
        $states = [];

        foreach ($members as $member) {
            $expectation = $this->expectationFor($current, $members, $states);
            $states[$member->id] = $this->sourceFinalizer->revalidate($member, $expectation);
        }

        return $this->expectationFor($current, $members, $states);
    }

    /**
     * @param Collection<int, AppInstanceRemovalMember> $members
     * @param array<int, AppInstanceSourceRevalidationState> $states
     */
    private function expectationFor(
        AppInstanceRemovalMember $current,
        Collection $members,
        array $states,
    ): AppInstanceSourceRevalidationExpectation {
        $required = $current->linked_worktree_paths;
        $permitted = $current->linked_worktree_paths;

        foreach ($members as $member) {
            if (
                $member->common_repository_path !== $current->common_repository_path
                || ! is_string($member->checkout_path)
            ) {
                continue;
            }

            $state = $states[$member->id] ?? null;

            if (
                $member->source_finalized_at !== null
                || $state === AppInstanceSourceRevalidationState::Completed
            ) {
                $required = array_values(array_diff($required, [$member->checkout_path]));
                $permitted = array_values(array_diff($permitted, [$member->checkout_path]));

                continue;
            }

            if ($state === AppInstanceSourceRevalidationState::ReceiptPendingCleanup) {
                $required = array_values(array_diff($required, [$member->checkout_path]));
            }
        }

        $required = array_values(array_unique($required));
        $permitted = array_values(array_unique($permitted));
        sort($required, SORT_STRING);
        sort($permitted, SORT_STRING);

        return new AppInstanceSourceRevalidationExpectation($required, $permitted, $states);
    }

    private function stepComplete(AppInstanceRemovalMember $member, AppInstanceRemovalStep $step): bool
    {
        return match ($step) {
            AppInstanceRemovalStep::SourcePreparation => $member->source_prepared_at !== null,
            AppInstanceRemovalStep::RouteTargetClear => $member->route_cleared_at !== null,
            AppInstanceRemovalStep::SourceFinalization => $member->source_finalized_at !== null,
            AppInstanceRemovalStep::RuntimeCleanup => $member->runtime_cleaned_at !== null,
            AppInstanceRemovalStep::RowDeletion => $member->row_deleted_at !== null,
        };
    }

    private function firstIncompleteStep(AppInstanceRemoval $operation): AppInstanceRemovalStep
    {
        foreach ($operation->members()->orderBy('position')->get() as $member) {
            foreach (AppInstanceRemovalStep::cases() as $step) {
                if (! $this->stepComplete($member, $step)) {
                    return $step;
                }
            }
        }

        return AppInstanceRemovalStep::RowDeletion;
    }

    /** @param array<int, AppInstanceSourceInventory> $inventories */
    private function inventoryDigest(int $requestedId, bool $force, array $inventories): string
    {
        $members = collect($inventories)
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
            'members' => $members,
        ], JSON_THROW_ON_ERROR));
    }

    private function fail(
        AppInstanceRemoval $operation,
        AppInstanceRemovalStep $step,
        Throwable $exception,
        bool $revalidation = false,
    ): never {
        $errorCode = $this->errorCode($exception);
        $operation->update([
            'status' => AppInstanceRemovalStatus::Failed,
            'current_step' => $step,
            'failed_step' => $step,
            'error_code' => $errorCode,
        ]);

        throw new AppInstanceRemovalException(
            errorCode: $errorCode,
            status: match (true) {
                $exception instanceof ResourceOperationException => $exception->status,
                $revalidation && $exception instanceof RuntimeConvergenceException => 409,
                default => 502,
            },
            removal: $operation->refresh()->load('members'),
            previous: $exception,
        );
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
