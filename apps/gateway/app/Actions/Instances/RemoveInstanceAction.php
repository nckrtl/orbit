<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Annotations\CancelInstanceAnnotationTasksAction;
use App\Actions\DatabaseConnections\DropOwnedDatabasesAction;
use App\Actions\Processes\CascadeInstanceProcessesAction;
use App\Actions\Schedules\CascadeInstanceSchedulesAction;
use App\Data\Instances\InstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceCreationRecovery;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalException;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceRevalidationExpectation;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Instances\Removal\ProductionInstanceContentRetention;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\InstanceTransfer;
use App\Models\Route;
use App\Models\RouteAnalyticsTracking;
use App\Models\RouteTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class RemoveInstanceAction implements InstanceRemover
{
    public function __construct(
        private DevelopmentInstanceSourceRemoval $sourceInspector,
        private DevelopmentInstanceSourceFinalizer $sourceFinalizer,
        private InstanceRemovalProjector $routes,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private InstanceEnvironmentOperationLock $environmentOperations,
        private ProcessAdmissionLock $processAdmissions,
        private CascadeInstanceProcessesAction $processes,
        private AppDevSourceOperationLock $sourceLock,
        private ProductionInstanceContentRetention $productionContent,
        private RouteStateResolver $routeState,
        private ?CascadeInstanceSchedulesAction $schedules = null,
        private ?RecordEventBroadcaster $broadcaster = null,
        private ?ProjectLifecycleRunner $lifecycle = null,
        private ?DropOwnedDatabasesAction $databases = null,
    ) {}

    public function execute(Instance $instance, bool $force, bool $runTeardown = true, bool $allowCascade = true, bool $requirePreActivation = false): InstanceRemoval
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $instanceId = $instance->id;
        $instanceName = $instance->name;
        $removal = $this->performRemoval($instance, $force, $runTeardown, $allowCascade, $requirePreActivation);
        $broadcaster = $this->broadcaster ?? app(RecordEventBroadcaster::class);

        if (Instance::query()->whereKey($instanceId)->exists()) {
            $broadcaster->broadcast(
                RecordEventType::InstanceUpdated,
                $instanceId,
                InstanceData::fromModel($instance->fresh() ?? $instance)->toArray(),
            );
        } else {
            $broadcaster->broadcast(
                RecordEventType::InstanceDeleted,
                $instanceId,
                ['id' => $instanceId, 'name' => $instanceName],
            );
        }

        return $removal;
    }

    private function performRemoval(Instance $instance, bool $force, bool $runTeardown, bool $allowCascade, bool $requirePreActivation): InstanceRemoval
    {
        if ($instance->placedOnAppProd()) {
            return $this->environmentOperations->run(
                [$instance->id],
                fn (): InstanceRemoval => $this->executeOwned($instance, $force, false, $allowCascade, $requirePreActivation),
            );
        }

        $ownerIds = $allowCascade ? $this->removalEnvironmentOwnerIds($instance->refresh(), $force) : [$instance->id];

        return $this->environmentOperations->run(
            $ownerIds,
            fn (): InstanceRemoval => $this->sourceLock->synchronized(
                $instance->node_id,
                function () use ($instance, $force, $ownerIds, $runTeardown, $allowCascade, $requirePreActivation): InstanceRemoval {
                    $currentOwnerIds = $allowCascade ? $this->removalEnvironmentOwnerIds($instance->refresh(), $force) : [$instance->id];

                    if ($currentOwnerIds !== $ownerIds) {
                        throw new ResourceOperationException(
                            errorCode: 'instance.removal_conflict',
                            message: 'The Instance removal owner set changed. Retry the request.',
                            status: 409,
                        );
                    }

                    return $this->executeOwned($instance, $force, $runTeardown, $allowCascade, $requirePreActivation);
                },
            ),
        );
    }

    private function executeOwned(Instance $instance, bool $force, bool $runTeardown, bool $allowCascade, bool $requirePreActivation): InstanceRemoval
    {
        $snapshot = $instance->refresh()->load($this->removalRelations());
        if ($requirePreActivation && $snapshot->status === InstanceState::Active) {
            throw new ResourceOperationException('instance.remove_refused', 'Failed-create cleanup cannot remove an activated Instance.', 409);
        }

        if ($snapshot->status === InstanceState::Removing) {
            return $this->resume($snapshot, $force);
        }

        return $this->advance($this->accept($snapshot, $force, $runTeardown, $allowCascade));
    }

    /** @return list<int> */
    private function removalEnvironmentOwnerIds(Instance $requested, bool $force): array
    {
        if (! $force || $requested->source_layout !== InstanceSourceLayout::Checkout->value) {
            return [$requested->id];
        }

        $query = Instance::query()
            ->where('project_id', $requested->project_id)
            ->where('node_id', $requested->node_id);
        $paths = $requested->registration_worktree_paths;

        if (is_array($paths) && $paths !== []) {
            $query->whereIn('checkout_path', $paths);
        } elseif (is_string($requested->registration_common_repository_path)) {
            $query->where('registration_common_repository_path', $requested->registration_common_repository_path);
        }

        $ids = $query
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => StoredInteger::from($id))
            ->values()
            ->all();
        $ids = array_values($ids);
        if (! in_array($requested->id, $ids, true)) {
            $ids[] = $requested->id;
            sort($ids, SORT_NUMERIC);
        }

        return $ids;
    }

    private function resume(Instance $instance, bool $force): InstanceRemoval
    {
        $member = InstanceRemovalMember::query()
            ->where('instance_id', $instance->id)
            ->whereNull('row_deleted_at')
            ->with('removal.members')
            ->first();

        if (! $member instanceof InstanceRemovalMember) {
            $this->conflict($instance);
        }

        $removal = $member->removal;

        if ($removal->requested_instance_id !== $instance->id) {
            $this->conflict($instance);
        }

        if ($removal->force !== $force) {
            if (! $force || $removal->force || $removal->status !== InstanceRemovalStatus::Failed) {
                $this->conflict($instance);
            }

            // Take over the accepted operation, not a newly inspected deletion set.
            // Keep its source digests and journals so quarantine remains authenticated.
            $removal->update(['force' => true]);
        }

        try {
            $this->revalidateUnfinishedSource($removal);
        } catch (Throwable $exception) {
            $this->fail($removal, $this->firstIncompleteStep($removal), $exception, revalidation: true);
        }

        $removal->update([
            'status' => InstanceRemovalStatus::Removing,
            'failed_step' => null,
            'error_code' => null,
        ]);

        return $this->advance($removal->refresh());
    }

    private function accept(Instance $instance, bool $force, bool $runTeardown, bool $allowCascade): InstanceRemoval
    {
        $this->assertSupported($instance);

        if ($instance->placedOnAppProd()) {
            return $this->acceptProduction($instance, $force);
        }

        return $this->sourceLock->synchronized(
            $instance->node_id,
            fn (): InstanceRemoval => $this->acceptLocked($instance, $force, $runTeardown, $allowCascade),
        );
    }

    private function acceptLocked(Instance $instance, bool $force, bool $runTeardown, bool $allowCascade): InstanceRemoval
    {
        $snapshot = $instance->refresh()->load($this->removalRelations());
        $this->assertSupported($snapshot);
        [$members, $inventories] = $this->deletionSet($snapshot, $force);

        if (! $allowCascade && $members->count() !== 1) {
            throw new ResourceOperationException('instance.remove_refused', 'Create rollback cannot remove other Instances.', 409);
        }

        if ($runTeardown) {
            $ranTeardown = false;

            foreach ($members as $member) {
                if ($this->failedCreation($member)) {
                    continue;
                }

                $ranTeardown = ($this->lifecycle ?? app(ProjectLifecycleRunner::class))->run($member, LifecyclePhase::Teardown) || $ranTeardown;
            }

            foreach ($ranTeardown ? $members : [] as $member) {
                $this->assertMemberPathAvailable($member);
                $after = $this->inspect($member, $force);
                $before = $inventories[$member->id];

                foreach (['layout', 'repositoryIdentity', 'checkoutPath', 'root', 'branch', 'startingCommit', 'commonRepositoryPath', 'sourceIdentity', 'linkedWorktreePaths'] as $field) {
                    if ($before->{$field} !== $after->{$field}) {
                        throw new ResourceOperationException(
                            errorCode: 'instance.remove_refused',
                            message: 'Teardown changed the source ownership. The Instance remains.',
                            status: 409,
                        );
                    }
                }

                $inventories[$member->id] = $after;
            }
        }

        $digest = $this->inventoryDigest($snapshot->id, $force, $inventories);

        $operation = $this->processAdmissions->run(
            array_values($members->pluck('id')->map(static fn (mixed $id): int => StoredInteger::from($id))->all()),
            fn (): InstanceRemoval => DB::transaction(function () use (
                $snapshot,
                $force,
                $members,
                $inventories,
                $digest,
            ): InstanceRemoval {
                $lockedMembers = Instance::query()
                    ->whereKey($members->pluck('id'))
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->get()
                    ->keyBy('id');
                $routeIds = $members
                    ->map(static fn (Instance $member): ?int => $member->routes->first()?->id)
                    ->filter();
                $lockedRoutes = Route::query()
                    ->with('targets')
                    ->whereKey($routeIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($lockedMembers->count() !== $members->count() || $lockedRoutes->count() !== $routeIds->count()) {
                    $this->conflict($snapshot);
                }

                foreach ($members as $member) {
                    $lockedMember = $lockedMembers->get($member->id);

                    if (
                        ! $lockedMember instanceof Instance
                        || ! $this->removableState($lockedMember)
                        || $lockedMember->project_id !== $member->project_id
                        || $lockedMember->node_id !== $member->node_id
                        || $lockedMember->checkout_path !== $member->checkout_path
                        || $lockedMember->source_layout !== $member->source_layout
                    ) {
                        $this->conflict($snapshot);
                    }

                    $route = $member->routes->first();

                    if (! $route instanceof Route) {
                        if (RouteTarget::query()->where('instance_id', $member->id)->exists()) {
                            $this->conflict($snapshot);
                        }

                        continue;
                    }

                    $lockedRoute = $lockedRoutes->get($route->id);

                    if (
                        ! $lockedRoute instanceof Route
                        || ! $this->removableRouteState($lockedRoute, $lockedMember)
                        || $lockedRoute->targets->count() !== 1
                        || $lockedRoute->targets->sole()->instance_id !== $lockedMember->id
                    ) {
                        $this->conflict($snapshot);
                    }
                }

                $operation = InstanceRemoval::query()->create([
                    'id' => (string) Str::uuid(),
                    'requested_instance_id' => $snapshot->id,
                    'requested_name' => $snapshot->name,
                    'force' => $force,
                    'inventory_digest' => $digest,
                    'total' => $members->count(),
                    'status' => InstanceRemovalStatus::Removing,
                    'current_step' => InstanceRemovalStep::SourcePreparation,
                ]);

                foreach ($members as $position => $member) {
                    $inventory = $inventories[$member->id];
                    $operation
                        ->members()
                        ->create([
                            'position' => $position,
                            'instance_id' => $member->id,
                            'project_id' => $member->project_id,
                            'node_id' => $member->node_id,
                            'route_id' => $member->routes->first()?->id,
                            'name' => $member->name,
                            'environment' => $member->defaultAppEnv(),
                            'source_layout' => $inventory->layout,
                            'repository_identity' => $inventory->repositoryIdentity,
                            'checkout_path' => $inventory->checkoutPath,
                            'root' => $member->effectiveRoot(),
                            'branch' => $inventory->branch,
                            'starting_commit' => $member->starting_commit,
                            'source_commit' => $inventory->startingCommit,
                            'common_repository_path' => $inventory->commonRepositoryPath,
                            'source_identity' => $inventory->sourceIdentity,
                            'linked_worktree_paths' => $inventory->linkedWorktreePaths,
                            'source_digest' => $inventory->digest,
                            'runtime_published' => $lockedMembers->get($member->id)?->status === InstanceState::Active,
                        ]);
                }

                Instance::query()
                    ->whereKey($members->pluck('id'))
                    ->update(['status' => InstanceState::Removing->value]);
                foreach ($members as $member) {
                    app(CancelInstanceAnnotationTasksAction::class)->execute($member->id);
                }

                return $operation->load('members');
            }),
        );

        return $operation;
    }

    private function acceptProduction(Instance $instance, bool $force): InstanceRemoval
    {
        $snapshot = $instance->refresh()->load($this->removalRelations());
        $this->assertSupported($snapshot);
        $route = $this->productionRoute($snapshot);
        $inventory = $this->productionContent->inventory($snapshot);

        $operation = $this->processAdmissions->run([$snapshot->id], fn (): InstanceRemoval => DB::transaction(function () use ($snapshot, $route, $inventory, $force): InstanceRemoval {
            $locked = Instance::query()->lockForUpdate()->findOrFail($snapshot->id);
            $lockedRoute = $route === null ? null : Route::query()
                ->with($this->productionRouteRelations())
                ->lockForUpdate()
                ->findOrFail($route->id);
            $locked->load(['project', 'node', 'routes.targets']);

            if (
                $locked->status !== InstanceState::Active
                || ($lockedRoute === null
                    ? RouteTarget::query()->where('instance_id', $locked->id)->exists()
                    : ! $this->productionRouteIsSafe($lockedRoute, $locked))
            ) {
                $this->conflict($snapshot);
            }

            $operation = InstanceRemoval::query()->create([
                'id' => (string) Str::uuid(),
                'requested_instance_id' => $snapshot->id,
                'requested_name' => $snapshot->name,
                'force' => $force,
                'inventory_digest' => $this->inventoryDigest($snapshot->id, $force, [
                    $snapshot->id => $inventory,
                ]),
                'total' => 1,
                'status' => InstanceRemovalStatus::Removing,
                'current_step' => InstanceRemovalStep::SourcePreparation,
            ]);
            $operation
                ->members()
                ->create([
                    'position' => 0,
                    'instance_id' => $snapshot->id,
                    'project_id' => $snapshot->project_id,
                    'node_id' => $snapshot->node_id,
                    'route_id' => $route?->id,
                    'name' => $snapshot->name,
                    'environment' => $snapshot->defaultAppEnv(),
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
            $locked->update(['status' => InstanceState::Removing]);
            app(CancelInstanceAnnotationTasksAction::class)->execute($locked->id);

            return $operation->load('members');
        }));

        return $operation;
    }

    private function assertSupported(Instance $instance): void
    {
        if (InstanceTransfer::query()
            ->where('instance_id', $instance->id)
            ->open()
            ->exists()) {
            throw new ResourceOperationException(
                errorCode: 'instance.transfer_incomplete',
                message: "Instance [{$instance->name}] has an incomplete or failed transfer that must be recovered before removal.",
                status: 409,
            );
        }

        if (Instance::query()
            ->where('clone_candidate_id', $instance->id)
            ->whereNull('clone_completed_at')
            ->exists()) {
            throw new ResourceOperationException(
                errorCode: 'instance.clone_in_progress',
                message: "Instance [{$instance->name}] is the candidate for an incomplete clone.",
                status: 409,
            );
        }

        if (RouteAnalyticsTracking::query()->where('instance_id', $instance->id)->exists()) {
            throw new ResourceOperationException(
                errorCode: 'analytics.tracking_hosts_exist',
                message: "Instance [{$instance->name}] still publishes a tracking host. Disable its analytics first.",
                status: 409,
            );
        }

        if (! $this->removableState($instance)) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "Instance [{$instance->name}] is not active.",
                status: 409,
            );
        }

        if (! $instance->placedOnAppDev() && ! $instance->placedOnAppProd()) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: 'The Instance environment cannot be removed.',
                status: 409,
            );
        }

        if (
            $instance->placedOnAppDev()
            && ! in_array(
                $instance->source_layout,
                [InstanceSourceLayout::Checkout->value, InstanceSourceLayout::Worktree->value],
                true,
            )
        ) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: 'Instance source layout is not removable.',
                status: 409,
            );
        }
    }

    /**
     * @return array{Collection<int, Instance>, array<int, InstanceSourceInventory>}
     */
    private function deletionSet(Instance $requested, bool $force): array
    {
        $this->assertMemberPathAvailable($requested);
        $requestedInventory = $this->inspect(
            $requested,
            $force,
            inspectContent: $requested->source_layout !== InstanceSourceLayout::Checkout->value,
        );
        $this->assertMemberPathAvailable($requested);
        $members = collect([$requested]);

        if ($requested->source_layout === InstanceSourceLayout::Checkout->value) {
            $registered = Instance::query()
                ->with(['project', 'node', 'routes.targets'])
                ->where('node_id', $requested->node_id)
                ->whereIn('checkout_path', $requestedInventory->linkedWorktreePaths)
                ->get();

            if ($registered->count() !== count($requestedInventory->linkedWorktreePaths)) {
                throw new ResourceOperationException(
                    errorCode: 'instance.remove_refused',
                    message: 'Every linked worktree must be a registered Instance before removal.',
                    status: 409,
                );
            }

            if ($registered->count() > 1 && (! $force || $this->failedCreation($requested))) {
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
                        ->sortBy(static fn (Instance $member): string => sprintf(
                            '%d:%s',
                            $member->source_layout === InstanceSourceLayout::Checkout->value ? 1 : 0,
                            $member->checkout_path,
                        ))
                        ->values()
                        ->all(),
                );
            }
        }

        $inventories = [];

        foreach ($members as $member) {
            $member->loadMissing(['project', 'node', 'routes.targets']);
            $this->assertSupported($member);

            if (
                $member->node_id !== $requested->node_id
                || $member->project_id !== $requested->project_id
                || $member->defaultAppEnv() !== $requested->defaultAppEnv()
                || $member->project->repository_identity !== $requested->project->repository_identity
            ) {
                throw new ResourceOperationException(
                    errorCode: 'instance.remove_refused',
                    message: "Instance [{$member->name}] is not owned by the requested source set.",
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
                $requested->source_layout === InstanceSourceLayout::Checkout->value
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
            $requested->source_layout === InstanceSourceLayout::Checkout->value
            && $members->where('source_layout', InstanceSourceLayout::Checkout->value)->count() !== 1
        ) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: 'The linked-worktree inventory does not identify one common checkout.',
                status: 409,
            );
        }

        return [$members, $inventories];
    }

    private function assertMemberPathAvailable(Instance $member): void
    {
        $path = StoragePath::tryParse($member->checkout_path);

        if (! $path instanceof StoragePath) {
            throw new ResourceOperationException(
                errorCode: 'instance.checkout_path_unsafe',
                message: "Instance [{$member->name}] has an unsafe checkout path.",
                status: 409,
            );
        }

        $this->checkoutOverlap->assertAvailable(
            $member->node_id,
            $path,
            'instance.checkout_path_unsafe',
            ignoreInstanceId: $member->id,
        );
    }

    private function route(Instance $instance): ?Route
    {
        if ($this->withoutRoute($instance)) {
            return null;
        }

        if ($instance->routes->count() !== 1) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "Instance [{$instance->name}] does not have one removable Route.",
                status: 409,
            );
        }

        $route = $instance->routes->sole();

        if (
            ! $this->removableRouteState($route, $instance)
            || $route->project_id !== $instance->project_id
            || $route->targets->count() !== 1
            || $route->targets->sole()->instance_id !== $instance->id
        ) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "Instance [{$instance->name}] Route is not safe to remove.",
                status: 409,
            );
        }

        return $route;
    }

    private function productionRoute(Instance $instance): ?Route
    {
        if ($this->withoutRoute($instance)) {
            return null;
        }

        if ($instance->routes->count() !== 1) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "Production Instance [{$instance->name}] does not have one removable Route.",
                status: 409,
            );
        }

        $route = $instance->routes->sole();

        if (! $this->productionRouteIsSafe($route, $instance)) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "Production Instance [{$instance->name}] Route is not safe to remove.",
                status: 409,
            );
        }

        return $route;
    }

    private function withoutRoute(Instance $instance): bool
    {
        return $instance->routes->isEmpty()
            && (! $instance->requiresRoute() || $instance->status === InstanceState::SourceResolved || $this->failedCreation($instance));
    }

    private function removableRouteState(Route $route, Instance $instance): bool
    {
        return $route->status === RouteStatus::Active
            || ($this->failedCreation($instance)
                && in_array($route->status, [RouteStatus::Pending, RouteStatus::Failed], true)
                && $route->node_id === $instance->node_id
                && $route->project_id === $instance->project_id);
    }

    private function failedCreation(Instance $instance): bool
    {
        return InstanceCreationRecovery::isPreActivation($instance);
    }

    private function removableState(Instance $instance): bool
    {
        if ($instance->status === InstanceState::Active || $this->failedCreation($instance)) {
            return true;
        }

        return $instance->status === InstanceState::SourceResolved
            && ! $instance->routes()->exists()
            && ! RouteTarget::query()->where('instance_id', $instance->id)->exists();
    }

    private function productionRouteIsSafe(Route $route, Instance $requested): bool
    {
        if (
            $route->status !== RouteStatus::Active
            || $route->targets->isEmpty()
            || ! $route->targets->contains('instance_id', $requested->id)
        ) {
            return false;
        }

        if ($route->cluster_id === null) {
            $node = $requested->node;
            $placement = $this->routeState->forNode($node);

            return
                $route->node_id === $requested->node_id
                && $route->publication === RoutePublication::Private
                && $route->targets->count() === 1
                && $route->targets->sole()->instance_id === $requested->id
                && $requested->placedOnAppProd()
                && $requested->status === InstanceState::Active
                && $node->status === LifecycleStatus::Active
                && $placement->nodeId === $node->id
                && $placement->clusterId === null
                && $node->roles->contains(
                    static fn ($role): bool => (
                        $role->role === RoleName::AppProd
                        && $role->status === LifecycleStatus::Active
                    ),
                );
        }

        if ($route->provenance !== RouteProvenance::Explicit) {
            return false;
        }

        $nodeIds = [];

        foreach ($route->targets as $position => $target) {
            $instance = $target->instance;
            $node = $instance->node;

            if (
                $target->position !== $position
                || $instance->project_id !== $route->project_id
                || ! $instance->placedOnAppProd()
                || $instance->status !== InstanceState::Active
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

    private function inspect(
        Instance $instance,
        bool $force,
        bool $inspectContent = true,
    ): InstanceSourceInventory {
        try {
            return $this->sourceInspector->inspect($instance, $force, $inspectContent);
        } catch (RuntimeConvergenceException $exception) {
            throw new ResourceOperationException(
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
                status: 409,
                previous: $exception,
            );
        }
    }

    private function advance(InstanceRemoval $operation): InstanceRemoval
    {
        $operation->load('members');

        foreach ($operation->members as $member) {
            foreach (InstanceRemovalStep::cases() as $step) {
                if ($this->stepComplete($member, $step)) {
                    continue;
                }

                $operation->update([
                    'status' => InstanceRemovalStatus::Removing,
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
        InstanceRemoval $operation,
        InstanceRemovalMember $member,
        InstanceRemovalStep $step,
    ): void {
        match ($step) {
            InstanceRemovalStep::SourcePreparation => $this->prepareSource($operation, $member),
            InstanceRemovalStep::RouteTargetClear => $this->clearRoute($member),
            InstanceRemovalStep::SourceFinalization => $this->finalizeSource($operation, $member),
            InstanceRemovalStep::RuntimeCleanup => $this->cleanupRuntime($member),
            InstanceRemovalStep::RowDeletion => $this->deleteRow($operation, $member),
        };
    }

    private function prepareSource(
        InstanceRemoval $operation,
        InstanceRemovalMember $member,
    ): void {
        if ($member->environment === 'production') {
            $this->productionContent->prepare($member);
            $member->update(['source_prepared_at' => now()]);

            return;
        }

        $expectation = $this->expectationFor(
            $member,
            $operation->members()->orderBy('position')->get(),
            [],
        );
        $this->sourceFinalizer->prepare($member, $expectation);
        $member->update(['source_prepared_at' => now()]);
    }

    private function clearRoute(InstanceRemovalMember $member): void
    {
        $outcome = $member->route_id === null ? 'none' : $this->routes->clearRouteTarget($member);
        $member->update(['route_cleared_at' => now(), 'route_outcome' => $outcome]);
    }

    private function finalizeSource(
        InstanceRemoval $operation,
        InstanceRemovalMember $member,
    ): void {
        if ($member->environment === 'production') {
            $this->productionContent->revalidate($member);
            $receipt = $this->productionContent->finalize($member);
            $member->update(['source_finalized_at' => now(), 'finalization_receipt' => $receipt]);

            return;
        }

        $expectation = $this->revalidateUnfinishedSources($operation, $member);
        // PHP-FPM refuses to start while any pool names a missing `chdir`, so the pool goes first. A
        // failed withdrawal keeps the source and leaves this step open for a retry.
        $this->routes->withdrawPhpPool($member);
        $receipt = $this->sourceFinalizer->finalize($member, $expectation);
        $member->update(['source_finalized_at' => now(), 'finalization_receipt' => $receipt]);
    }

    private function cleanupRuntime(InstanceRemovalMember $member): void
    {
        ($this->schedules ?? app(CascadeInstanceSchedulesAction::class))->execute($member->instance_id);
        $this->processes->execute($member->instance_id);
        ($this->databases ?? app(DropOwnedDatabasesAction::class))->execute($member->instance_id);

        // A routed create can publish part of its runtime before activation, and its Route can be
        // destroyed before the Instance, so a development member never trusts route_id alone: every
        // development removal converges its Node from stored state, which drops any pool left behind.
        if ($member->runtime_published || $member->route_id !== null || $member->environment === 'development') {
            $this->routes->cleanupRuntime($member);
        }
        $member->update(['runtime_cleaned_at' => now()]);
    }

    private function deleteRow(InstanceRemoval $operation, InstanceRemovalMember $member): void
    {
        DB::transaction(function () use ($operation, $member): void {
            $lockedOperation = InstanceRemoval::query()->lockForUpdate()->findOrFail($operation->id);
            $lockedMember = $lockedOperation->members()->lockForUpdate()->findOrFail($member->id);
            InstanceTransfer::query()
                ->where('instance_id', $member->instance_id)
                ->closed()
                ->update(['instance_id' => null]);
            app(CancelInstanceAnnotationTasksAction::class)->execute($member->instance_id);
            Instance::query()->lockForUpdate()->findOrFail($member->instance_id)->delete();
            $lockedMember->update(['row_deleted_at' => now()]);

            if ($lockedOperation->members()->whereNull('row_deleted_at')->exists()) {
                return;
            }

            $lockedOperation->update([
                'status' => InstanceRemovalStatus::Completed,
                'current_step' => null,
                'failed_step' => null,
                'error_code' => null,
            ]);
        });
    }

    private function revalidateUnfinishedSource(InstanceRemoval $operation): void
    {
        $member = $operation->members()->whereNull('row_deleted_at')->orderBy('position')->first();

        if (! $member instanceof InstanceRemovalMember) {
            return;
        }

        if ($member->environment === 'production') {
            $this->productionContent->revalidate($member);

            return;
        }

        $this->revalidateUnfinishedSources($operation, $member);
    }

    private function revalidateUnfinishedSources(
        InstanceRemoval $operation,
        InstanceRemovalMember $current,
    ): InstanceSourceRevalidationExpectation {
        $members = $operation->members()->orderBy('position')->get();
        $developmentNodeIds = $members
            ->where('environment', 'development')
            ->pluck('node_id')
            ->map(static fn (mixed $id): int => StoredInteger::from($id))
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
            $developmentNodeIds->sole(),
            fn (): InstanceSourceRevalidationExpectation => $this->revalidateUnfinishedSourcesLocked(
                $members,
                $current,
            ),
        );
    }

    /**
     * @param  Collection<int, InstanceRemovalMember>  $members
     */
    private function revalidateUnfinishedSourcesLocked(
        Collection $members,
        InstanceRemovalMember $current,
    ): InstanceSourceRevalidationExpectation {
        $states = [];

        foreach ($members as $member) {
            $expectation = $this->expectationFor($current, $members, $states);
            $states[$member->id] = $this->sourceFinalizer->revalidate($member, $expectation);
        }

        return $this->expectationFor($current, $members, $states);
    }

    /**
     * @param  Collection<int, InstanceRemovalMember>  $members
     * @param  array<int, InstanceSourceRevalidationState>  $states
     */
    private function expectationFor(
        InstanceRemovalMember $current,
        Collection $members,
        array $states,
    ): InstanceSourceRevalidationExpectation {
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
                || $state === InstanceSourceRevalidationState::Completed
            ) {
                $required = array_values(array_diff($required, [$member->checkout_path]));
                $permitted = array_values(array_diff($permitted, [$member->checkout_path]));

                continue;
            }

            if ($state === InstanceSourceRevalidationState::ReceiptPendingCleanup) {
                $required = array_values(array_diff($required, [$member->checkout_path]));
            }
        }

        $required = array_values(array_unique($required));
        $permitted = array_values(array_unique($permitted));
        sort($required, SORT_STRING);
        sort($permitted, SORT_STRING);

        return new InstanceSourceRevalidationExpectation($required, $permitted, $states);
    }

    private function stepComplete(InstanceRemovalMember $member, InstanceRemovalStep $step): bool
    {
        return match ($step) {
            InstanceRemovalStep::SourcePreparation => $member->source_prepared_at !== null,
            InstanceRemovalStep::RouteTargetClear => $member->route_cleared_at !== null,
            InstanceRemovalStep::SourceFinalization => $member->source_finalized_at !== null,
            InstanceRemovalStep::RuntimeCleanup => $member->runtime_cleaned_at !== null,
            InstanceRemovalStep::RowDeletion => $member->row_deleted_at !== null,
        };
    }

    private function firstIncompleteStep(InstanceRemoval $operation): InstanceRemovalStep
    {
        foreach ($operation->members()->orderBy('position')->get() as $member) {
            foreach (InstanceRemovalStep::cases() as $step) {
                if (! $this->stepComplete($member, $step)) {
                    return $step;
                }
            }
        }

        return InstanceRemovalStep::RowDeletion;
    }

    /** @param array<int, InstanceSourceInventory> $inventories */
    private function inventoryDigest(int $requestedId, bool $force, array $inventories): string
    {
        $members = collect($inventories)
            ->sortKeys()
            ->map(static fn (InstanceSourceInventory $inventory): array => [
                'id' => $inventory->instanceId,
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
        InstanceRemoval $operation,
        InstanceRemovalStep $step,
        Throwable $exception,
        bool $revalidation = false,
    ): never {
        $errorCode = $this->errorCode($exception);
        $operation->update([
            'status' => InstanceRemovalStatus::Failed,
            'current_step' => $step,
            'failed_step' => $step,
            'error_code' => $errorCode,
        ]);

        throw new InstanceRemovalException(
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

    private function conflict(Instance $instance): never
    {
        throw new ResourceOperationException(
            errorCode: 'instance.removal_conflict',
            message: "Instance [{$instance->name}] belongs to a different removal request.",
            status: 409,
        );
    }

    /** @return list<string> */
    private function removalRelations(): array
    {
        return [
            'project',
            'node',
            'routes.targets.instance.node.roles',
            'routes.cluster.routerAssignment.node',
        ];
    }

    /** @return list<string> */
    private function productionRouteRelations(): array
    {
        return [
            'targets.instance.node.roles',
            'cluster.routerAssignment.node',
        ];
    }
}
