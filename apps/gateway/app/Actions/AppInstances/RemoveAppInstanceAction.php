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
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceFinalizer;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceRemoval;
use App\Domain\AppInstances\Removal\ProductionAppInstanceContentRetention;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\AppInstanceRemovalMember;
use App\Models\Route;
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
        private ProductionAppInstanceContentRetention $productionContent,
    ) {}

    public function execute(AppInstance $appInstance, bool $force): AppInstanceRemoval
    {
        $snapshot = $appInstance->refresh()->load($this->removalRelations());

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

        if ($appInstance->environment === 'production') {
            return $this->acceptProduction($appInstance, $force);
        }

        return $this->sourceLock->synchronized(
            $appInstance->node_id,
            fn (): AppInstanceRemoval => $this->acceptLocked($appInstance, $force),
        );
    }

    private function acceptLocked(AppInstance $appInstance, bool $force): AppInstanceRemoval
    {
        $snapshot = $appInstance->refresh()->load($this->removalRelations());
        $this->assertSupported($snapshot);
        $route = $this->route($snapshot);
        $path = StoragePath::tryParse($snapshot->checkout_path);

        if (! $path instanceof StoragePath) {
            throw new ResourceOperationException(
                errorCode: 'instance.checkout_path_unsafe',
                message: "AppInstance [{$snapshot->name}] has an unsafe checkout path.",
                status: 409,
            );
        }

        $this->checkoutOverlap->assertAvailable(
            $snapshot->node_id,
            $path,
            'instance.checkout_path_unsafe',
            ignoreAppInstanceId: $snapshot->id,
        );
        $inventory = $this->inspect($snapshot, $force);

        if ($inventory->linkedWorktreePaths !== [$snapshot->checkout_path]) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "AppInstance [{$snapshot->name}] checkout has linked worktrees; cascade removal is not available.",
                status: 409,
            );
        }

        $this->checkoutOverlap->assertAvailable(
            $snapshot->node_id,
            $path,
            'instance.checkout_path_unsafe',
            ignoreAppInstanceId: $snapshot->id,
        );

        /** @var AppInstanceRemoval $operation */
        $operation = DB::transaction(function () use ($snapshot, $route, $inventory, $force): AppInstanceRemoval {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($snapshot->id);
            $lockedRoute = Route::query()->with('targets')->lockForUpdate()->findOrFail($route->id);

            if (
                $locked->status !== AppInstanceState::Active
                || $locked->migration_required
                || $lockedRoute->status !== RouteStatus::Active
                || $lockedRoute->targets->count() !== 1
                || $lockedRoute->targets->sole()->app_instance_id !== $locked->id
            ) {
                $this->conflict($snapshot);
            }

            $operation = AppInstanceRemoval::query()->create([
                'id' => (string) Str::uuid(),
                'requested_app_instance_id' => $snapshot->id,
                'requested_name' => $snapshot->name,
                'force' => $force,
                'inventory_digest' => $this->inventoryDigest($snapshot->id, $force, $inventory),
                'total' => 1,
                'status' => AppInstanceRemovalStatus::Removing,
                'current_step' => AppInstanceRemovalStep::SourcePreparation,
            ]);
            $operation
                ->members()
                ->create([
                    'position' => 0,
                    'app_instance_id' => $snapshot->id,
                    'app_id' => $snapshot->app_id,
                    'node_id' => $snapshot->node_id,
                    'route_id' => $route->id,
                    'name' => $snapshot->name,
                    'environment' => $snapshot->environment,
                    'source_layout' => $inventory->layout,
                    'repository_identity' => $inventory->repositoryIdentity,
                    'checkout_path' => $inventory->checkoutPath,
                    'root' => $snapshot->effectiveRoot(),
                    'branch' => $inventory->branch,
                    'starting_commit' => $inventory->startingCommit,
                    'common_repository_path' => $inventory->commonRepositoryPath,
                    'source_identity' => $inventory->sourceIdentity,
                    'linked_worktree_paths' => $inventory->linkedWorktreePaths,
                    'source_digest' => $inventory->digest,
                ]);
            $locked->update(['status' => AppInstanceState::Removing]);

            return $operation->load('members');
        });

        return $operation;
    }

    private function acceptProduction(AppInstance $appInstance, bool $force): AppInstanceRemoval
    {
        $snapshot = $appInstance->refresh()->load($this->removalRelations());
        $this->assertSupported($snapshot);
        $route = $this->productionRoute($snapshot);
        $inventory = $this->productionContent->inventory($snapshot);

        /** @var AppInstanceRemoval $operation */
        $operation = DB::transaction(function () use ($snapshot, $route, $inventory, $force): AppInstanceRemoval {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($snapshot->id);
            $lockedRoute = Route::query()
                ->with($this->productionRouteRelations())
                ->lockForUpdate()
                ->findOrFail($route->id);
            $locked->load(['app', 'node', 'routes.targets']);

            if ($locked->status !== AppInstanceState::Active || ! $this->productionRouteIsSafe($lockedRoute, $locked)) {
                $this->conflict($snapshot);
            }

            $operation = AppInstanceRemoval::query()->create([
                'id' => (string) Str::uuid(),
                'requested_app_instance_id' => $snapshot->id,
                'requested_name' => $snapshot->name,
                'force' => $force,
                'inventory_digest' => $this->inventoryDigest($snapshot->id, $force, $inventory),
                'total' => 1,
                'status' => AppInstanceRemovalStatus::Removing,
                'current_step' => AppInstanceRemovalStep::SourcePreparation,
            ]);
            $operation
                ->members()
                ->create([
                    'position' => 0,
                    'app_instance_id' => $snapshot->id,
                    'app_id' => $snapshot->app_id,
                    'node_id' => $snapshot->node_id,
                    'route_id' => $route->id,
                    'name' => $snapshot->name,
                    'environment' => $snapshot->environment,
                    'source_layout' => $inventory->layout,
                    'repository_identity' => $inventory->repositoryIdentity,
                    'checkout_path' => $inventory->checkoutPath,
                    'root' => $inventory->root,
                    'branch' => $inventory->branch,
                    'starting_commit' => $inventory->startingCommit,
                    'common_repository_path' => $inventory->commonRepositoryPath,
                    'source_identity' => $inventory->sourceIdentity,
                    'linked_worktree_paths' => $inventory->linkedWorktreePaths,
                    'source_digest' => $inventory->digest,
                ]);
            $locked->update(['status' => AppInstanceState::Removing]);

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

        if (! in_array($appInstance->environment, ['development', 'production'], true)) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: 'The AppInstance environment cannot be removed.',
                status: 409,
            );
        }

        if (
            $appInstance->environment === 'development'
            && $appInstance->source_layout !== AppInstanceSourceLayout::Checkout->value
        ) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: 'Worktree AppInstance removal is not available.',
                status: 409,
            );
        }
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

    private function productionRoute(AppInstance $appInstance): Route
    {
        if ($appInstance->routes->count() !== 1) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "Production AppInstance [{$appInstance->name}] does not have one removable Route.",
                status: 409,
            );
        }

        $route = $appInstance->routes->sole();

        if (! $this->productionRouteIsSafe($route, $appInstance)) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "Production AppInstance [{$appInstance->name}] Route is not safe to remove.",
                status: 409,
            );
        }

        return $route;
    }

    private function productionRouteIsSafe(Route $route, AppInstance $requested): bool
    {
        if (
            $route->status !== RouteStatus::Active
            || $route->provenance !== RouteProvenance::Explicit
            || $route->cluster_id === null
            || $route->targets->isEmpty()
            || ! $route->targets->contains('app_instance_id', $requested->id)
        ) {
            return false;
        }

        $nodeIds = [];

        foreach ($route->targets as $position => $target) {
            $instance = $target->appInstance;
            $node = $instance->node;

            if (
                $target->position !== $position
                || $instance->app_id !== $route->app_id
                || $instance->environment !== 'production'
                || $instance->status !== AppInstanceState::Active
                || $node->status !== LifecycleStatus::Active
                || $node->cluster_id !== $route->cluster_id
                || ! $node->roles->contains(
                    static fn ($role): bool => $role->role === RoleName::AppProd
                    && $role->status === LifecycleStatus::Active,
                )
                || isset($nodeIds[$node->id])
            ) {
                return false;
            }

            $nodeIds[$node->id] = true;
        }

        return true;
    }

    private function inspect(AppInstance $appInstance, bool $force): AppInstanceSourceInventory
    {
        try {
            return $this->sourceInspector->inspect($appInstance, $force);
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
            AppInstanceRemovalStep::SourcePreparation => $this->prepareSource($member),
            AppInstanceRemovalStep::RouteTargetClear => $this->clearRoute($member),
            AppInstanceRemovalStep::SourceFinalization => $this->finalizeSource($member),
            AppInstanceRemovalStep::RuntimeCleanup => $this->cleanupRuntime($member),
            AppInstanceRemovalStep::RowDeletion => $this->deleteRow($operation, $member),
        };
    }

    private function prepareSource(AppInstanceRemovalMember $member): void
    {
        if ($member->environment === 'production') {
            $this->productionContent->prepare($member);
        } else {
            $this->sourceFinalizer->prepare($member);
        }

        $member->update(['source_prepared_at' => now()]);
    }

    private function clearRoute(AppInstanceRemovalMember $member): void
    {
        $outcome = $this->routes->clearRouteTarget($member);
        $member->update(['route_cleared_at' => now(), 'route_outcome' => $outcome]);
    }

    private function finalizeSource(AppInstanceRemovalMember $member): void
    {
        if ($member->environment === 'production') {
            $this->productionContent->revalidate($member);
            $receipt = $this->productionContent->finalize($member);
        } else {
            $this->sourceFinalizer->revalidate($member);
            $receipt = $this->sourceFinalizer->finalize($member);
        }

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
        $member = $operation->members()->whereNull('source_finalized_at')->orderBy('position')->first();

        if (! $member instanceof AppInstanceRemovalMember) {
            return;
        }

        if ($member->environment === 'production') {
            $this->productionContent->revalidate($member);

            return;
        }

        $this->sourceFinalizer->revalidate($member);
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

    private function inventoryDigest(int $requestedId, bool $force, AppInstanceSourceInventory $inventory): string
    {
        return hash('sha256', json_encode([
            'requested_id' => $requestedId,
            'force' => $force,
            'members' => [[
                'id' => $inventory->appInstanceId,
                'digest' => $inventory->digest,
                'worktrees' => $inventory->linkedWorktreePaths,
            ]],
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

    /** @return list<string> */
    private function removalRelations(): array
    {
        return [
            'app',
            'node',
            'routes.targets.appInstance.node.roles',
            'routes.cluster.routerAssignment.node',
        ];
    }

    /** @return list<string> */
    private function productionRouteRelations(): array
    {
        return [
            'targets.appInstance.node.roles',
            'cluster.routerAssignment.node',
        ];
    }
}
