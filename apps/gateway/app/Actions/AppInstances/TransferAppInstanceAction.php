<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Data\AppInstances\TransferAppInstanceData;
use App\Domain\AppDev\AgentationPortAllocator;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentImporter;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentReader;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRenderer;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriter;
use App\Domain\AppInstances\Sqlite\AppInstanceSqliteSeeder;
use App\Domain\AppInstances\Sqlite\SqliteSeedPlacement;
use App\Domain\AppInstances\Transfer\AppInstanceTransferRouteProjector;
use App\Domain\AppInstances\Transfer\AppInstanceTransferRuntime;
use App\Domain\AppInstances\Transfer\AppInstanceTransferSource;
use App\Domain\AppInstances\Transfer\AppInstanceTransferStatus;
use App\Domain\AppInstances\Transfer\AppInstanceTransferStep;
use App\Domain\AppInstances\Transfer\TransferSourceCapture;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Domain\Routes\RoutePlacement;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\AppInstanceTransfer;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class TransferAppInstanceAction
{
    public function __construct(
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private AppInstanceDestinationGuard $destinationGuard,
        private AppInstanceEnvironmentOperationLock $environmentOperations,
        private AppDevSourceOperationLock $sourceLock,
        private AppInstanceTransferSource $sources,
        private AppInstanceTransferRuntime $runtime,
        private AppInstanceSqliteSeeder $sqlite,
        private AppInstanceEnvironmentContextResolver $contexts,
        private AppInstanceEnvironmentReader $environmentReader,
        private AppInstanceEnvironmentImporter $environmentImporter,
        private AppInstanceEnvironmentStore $environmentStore,
        private AppInstanceEnvironmentRenderer $environmentRenderer,
        private AppInstanceEnvironmentWriter $environmentWriter,
        private RouteStateResolver $routeState,
        private DevelopmentRouteProjector $projection,
        private AppInstanceTransferRouteProjector $transferProjection,
        private DevelopmentProjectionOperationLock $projectionOwner,
        private ClusterRouterOperationLock $routerOwner,
    ) {}

    /** @return array{appInstance: AppInstance, transfer: AppInstanceTransfer, created: bool} */
    public function execute(AppInstance $instance, TransferAppInstanceData $data): array
    {
        $instance->loadMissing(['app', 'node', 'routes.targets', 'removalMember']);
        $existing = $this->existingTransfer($instance, $data);

        if ($existing instanceof AppInstanceTransfer && $existing->completed_at !== null) {
            return [
                'appInstance' => $instance->refresh()->load(['routes.targets', 'node']),
                'transfer' => $existing,
                'created' => false,
            ];
        }

        if ($existing?->cutover_at !== null) {
            return $this->executeOwned($instance, $data, $existing, null);
        }

        $sourceRoute = $existing instanceof AppInstanceTransfer
            ? Route::query()->findOrFail($existing->source_route_id)
            : $this->authoritativeRoute($instance);
        $clusterId = $sourceRoute->cluster_id;
        if ($clusterId === null) {
            throw $this->conflict('instance.standalone_unsupported', 'The source Route must belong to an active Cluster.');
        }

        return $this->routerOwner->run(
            $clusterId,
            fn (): array => $this->executeOwned($instance, $data, $existing, $clusterId),
        );
    }

    /** @return array{appInstance: AppInstance, transfer: AppInstanceTransfer, created: bool} */
    private function executeOwned(
        AppInstance $instance,
        TransferAppInstanceData $data,
        ?AppInstanceTransfer $existing,
        ?int $sourceClusterId,
    ): array {
        if ($existing instanceof AppInstanceTransfer) {
            $transfer = $existing;
            $created = false;
        } else {
            $transfer = $this->environmentOperations->run(
                [$instance->id],
                fn (): AppInstanceTransfer => $this->reserve($instance->refresh(), $data),
            );
            $created = true;
        }

        try {
            $result = $this->environmentOperations->run(
                [$instance->id],
                fn (): AppInstance => $this->sourceLock->synchronized(
                    $transfer->destination_node_id,
                    fn (): AppInstance => $this->sourceLock->synchronized(
                        $transfer->source_node_id,
                        fn (): AppInstance => $this->resume($instance->id, $transfer->id, $sourceClusterId),
                    ),
                ),
            );
        } catch (Throwable $exception) {
            $this->recordFailure($transfer->id, $exception);

            throw $exception;
        }

        return [
            'appInstance' => $result,
            'transfer' => AppInstanceTransfer::query()->findOrFail($transfer->id),
            'created' => $created,
        ];
    }

    private function existingTransfer(AppInstance $instance, TransferAppInstanceData $data): ?AppInstanceTransfer
    {
        $existing = AppInstanceTransfer::query()
            ->where('app_instance_id', $instance->id)
            ->whereNull('completed_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $existing instanceof AppInstanceTransfer) {
            $completed = AppInstanceTransfer::query()
                ->where('app_instance_id', $instance->id)
                ->whereNotNull('completed_at')
                ->orderByDesc('completed_at')
                ->first();

            if (
                $completed instanceof AppInstanceTransfer
                && $this->sameRequest($completed, $instance, $data)
            ) {
                return $completed;
            }

            return null;
        }

        if (! $this->sameRequest($existing, $instance, $data)) {
            throw $this->conflict(
                'instance.transfer_retry_conflict',
                'Only the identical transfer request can resume this AppInstance.',
            );
        }

        return $existing;
    }

    private function sameRequest(
        AppInstanceTransfer $transfer,
        AppInstance $instance,
        TransferAppInstanceData $data,
    ): bool {
        return $transfer->destination_node_id === $data->nodeId
            && $transfer->requested_name === $data->name
            && $transfer->sqlite_source_path === $data->sqliteSourcePath
            && $transfer->app_instance_id === $instance->id;
    }

    private function reserve(AppInstance $instance, TransferAppInstanceData $data): AppInstanceTransfer
    {
        [$destination, $path, $domain, $route] = $this->preflight($instance, $data);

        return AppInstanceTransfer::query()->create([
            'app_instance_id' => $instance->id,
            'source_node_id' => $instance->node_id,
            'source_router_node_id' => $this->sourceRouterId($route),
            'destination_node_id' => $destination->id,
            'requested_name' => $data->name,
            'destination_name' => $data->name ?? $instance->name,
            'destination_path' => $path->value,
            'destination_domain' => $domain,
            'sqlite_source_path' => $data->sqliteSourcePath,
            'source_layout' => $instance->source_layout,
            'source_path' => $instance->checkout_path,
            'common_repository_path' => $instance->registration_common_repository_path,
            'source_route_id' => $route->id,
            'status' => AppInstanceTransferStatus::Reserved,
            'current_step' => AppInstanceTransferStep::Reserved,
        ]);
    }

    /** @return array{Node, StoragePath, string, Route} */
    private function preflight(AppInstance $instance, TransferAppInstanceData $data): array
    {
        $instance->refresh()->loadMissing(['app', 'node', 'routes.targets']);
        $this->assertEligibleSource($instance);
        $destination = Node::query()->findOrFail($data->nodeId);
        $this->assertEligibleDestination($instance, $destination);
        $name = $data->name ?? $instance->name;
        $this->assertDestinationIdentity($instance, $name);
        $path = $this->destinationPath($destination, $instance->app->slug, $name);
        $this->assertDestinationAvailable($destination, $path);
        $route = $this->authoritativeRoute($instance);
        $placement = $this->routeState->forNode($destination);

        if ($placement->clusterId !== null) {
            $this->routeState->assertRouter($placement->clusterId);
        }

        $domain = $this->destinationDomain($instance, $route, $name, $placement);
        $this->assertDestinationDomain($instance, $route, $domain);

        return [$destination, $path, $domain, $route];
    }

    private function assertEligibleSource(AppInstance $instance): void
    {
        if ($instance->status !== AppInstanceState::Active || $instance->provisioning_step !== 'active') {
            throw $this->conflict('instance.lifecycle_conflict', 'The AppInstance is not active for transfer.');
        }

        if ($instance->environment !== 'development') {
            throw $this->conflict(
                'instance.production_refused',
                'Transfer accepts only an active development AppInstance.',
            );
        }

        if ($instance->migration_required) {
            throw $this->conflict('instance.migration_required', 'The AppInstance requires source migration before transfer.');
        }

        if ($instance->removalMember !== null) {
            throw $this->conflict('instance.removal_conflict', 'The AppInstance is being removed.');
        }

        $this->assertActiveAppDevCluster($instance->node, 'source');
    }

    private function assertEligibleDestination(AppInstance $instance, Node $destination): void
    {
        if ($destination->id === $instance->node_id) {
            throw $this->conflict('instance.same_node', 'Transfer requires a distinct destination Node.');
        }

        $this->assertActiveAppDevCluster($destination, 'destination');
        $instance->loadMissing('app');
        app(DevelopmentNodeExclusion::class)->assertAvailable($instance->app, $destination);
    }

    private function assertActiveAppDevCluster(Node $node, string $role): void
    {
        if ($node->status !== LifecycleStatus::Active || $node->platform !== 'linux') {
            throw $this->conflict('instance.node_inactive', "The {$role} Node is not an active Linux Node.");
        }

        if (! $node->roles()->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active)->exists()) {
            throw $this->conflict('instance.node_not_app_dev', "The {$role} Node has no active app-dev role.");
        }

        $cluster = $node->cluster_id === null ? null : Cluster::query()->find($node->cluster_id);

        if (! $cluster instanceof Cluster || $cluster->state !== ClusterState::Active) {
            throw $this->conflict(
                'instance.standalone_unsupported',
                "The {$role} Node must belong to an active Cluster.",
            );
        }
    }

    private function assertDestinationIdentity(AppInstance $instance, string $name): void
    {
        $taken = AppInstance::query()
            ->where('app_id', $instance->app_id)
            ->where('name', $name)
            ->whereKeyNot($instance->id)
            ->exists();

        if ($taken) {
            throw $this->conflict(
                'instance.identity_conflict',
                "AppInstance name [{$name}] is already owned. Retry with a different name.",
            );
        }
    }

    private function destinationPath(Node $destination, string $appSlug, string $name): StoragePath
    {
        $account = $this->accounts->resolve($destination);
        $roots = $this->storageRoots->resolveApps(
            $this->nodeSettings->fromStored($destination->settings),
            $account,
        );

        return $roots->instance->append($appSlug, $name);
    }

    private function assertDestinationAvailable(Node $destination, StoragePath $path): void
    {
        try {
            $this->checkoutOverlap->assertAvailable(
                $destination->id,
                $path,
                'instance.destination_exists',
            );
            $this->destinationGuard->assertUnoccupied($destination, $path);
        } catch (ResourceOperationException $exception) {
            if (in_array($exception->errorCode, [
                'instance.destination_exists',
                'instance.path_taken',
                'instance.migration_conflict',
            ], true)) {
                throw $this->destinationExists($path);
            }

            throw $exception;
        }
    }

    private function destinationExists(StoragePath $path): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'instance.destination_exists',
            message: "destination already exists. Retry with a different name to use another destination path. Occupied path: [{$path->value}].",
            status: 409,
            details: [
                'rename' => 'Pass name to recalculate the destination path and generated domain.',
            ],
        );
    }

    private function authoritativeRoute(AppInstance $instance): Route
    {
        $route = $instance->authoritativeRoute();

        if (! $route instanceof Route) {
            throw $this->conflict('instance.lifecycle_conflict', 'The AppInstance has no authoritative Route.');
        }

        if ($route->replaced_by_route_id !== null || $route->replaces_route_id !== null) {
            throw $this->conflict(
                'instance.lifecycle_conflict',
                'Transfer cannot start while a Route domain change is incomplete.',
            );
        }

        return $route;
    }

    private function destinationDomain(
        AppInstance $instance,
        Route $route,
        string $name,
        RoutePlacement $placement,
    ): string {
        if ($route->provenance === RouteProvenance::Explicit) {
            return $route->domain;
        }

        return $this->routeState->generatedDomain($instance->app->slug, $name, $placement->effectiveTld);
    }

    private function assertDestinationDomain(AppInstance $instance, Route $route, string $domain): void
    {
        $taken = Route::query()
            ->where('domain', $domain)
            ->whereKeyNot($route->id)
            ->exists();

        if ($taken) {
            throw $this->conflict('route.domain_conflict', "Route domain [{$domain}] is already owned.");
        }
    }

    private function resume(int $instanceId, string $transferId, ?int $sourceClusterId): AppInstance
    {
        $instance = AppInstance::query()->with(['app', 'node', 'routes.targets'])->findOrFail($instanceId);
        $transfer = AppInstanceTransfer::query()->findOrFail($transferId);
        $destination = Node::query()->findOrFail($transfer->destination_node_id);
        $path = StoragePath::parse($transfer->destination_path);

        if ($transfer->source_router_node_id === null) {
            if ($transfer->cutover_at !== null) {
                throw $this->conflict(
                    'instance.transfer_source_router_unknown',
                    'The original Router was not recorded. Recover its identity before retrying cleanup.',
                );
            }

            $transfer->update([
                'source_router_node_id' => $this->sourceRouterId(Route::query()->findOrFail($transfer->source_route_id)),
            ]);
        }

        if ($transfer->current_step === AppInstanceTransferStep::Reserved) {
            app(VitePortAllocator::class)->assign($instance);
            app(VitePortAllocator::class)->assign($instance, $destination);
            $capture = $this->sources->capture($instance);
            $this->checkpoint($transfer, AppInstanceTransferStep::SourceCaptured, [
                'common_repository_path' => $capture->commonRepositoryPath ?? $transfer->common_repository_path,
            ]);
            $this->materializeDestination($capture, $destination, $path, $transfer);
        } elseif ($transfer->current_step === AppInstanceTransferStep::SourceCaptured) {
            $this->materializeDestination($this->sources->capture($instance), $destination, $path, $transfer);
        }

        if ($transfer->current_step === AppInstanceTransferStep::DestinationCheckoutCreated) {
            $this->runtime->pause($instance);
            $this->checkpoint($transfer, AppInstanceTransferStep::SourcePaused);
        }

        if ($transfer->current_step === AppInstanceTransferStep::SourcePaused) {
            $this->transferSqlite($instance, $destination, $transfer);
            $this->checkpoint($transfer, AppInstanceTransferStep::SqliteTransferred);
        }

        if ($transfer->current_step === AppInstanceTransferStep::SqliteTransferred) {
            $this->importEnvironment($instance);
            $this->checkpoint($transfer, AppInstanceTransferStep::EnvironmentImported);
        }

        if ($transfer->current_step === AppInstanceTransferStep::EnvironmentImported) {
            $this->rebuildDestinationEnvironment($instance, $destination, $transfer);
            $this->checkpoint($transfer, AppInstanceTransferStep::EnvironmentRebuilt);
        }

        if ($transfer->current_step === AppInstanceTransferStep::EnvironmentRebuilt) {
            $this->checkpoint($transfer, AppInstanceTransferStep::RuntimeRelocated);
        }

        if ($transfer->current_step === AppInstanceTransferStep::RuntimeRelocated) {
            $this->prepareRoute($instance, $destination, $transfer);
            $this->checkpoint($transfer, AppInstanceTransferStep::RoutePrepared);
        }

        if ($transfer->current_step === AppInstanceTransferStep::RoutePrepared) {
            $this->cutover($instance, $destination, $transfer, $sourceClusterId);
        }

        $instance = AppInstance::query()->with(['app', 'node', 'routes.targets'])->findOrFail($instanceId);
        $transfer = AppInstanceTransfer::query()->findOrFail($transferId);

        if ($transfer->current_step === AppInstanceTransferStep::Cutover) {
            $this->runtime->relocate(
                $instance,
                $destination,
                $transfer->source_path,
                $transfer->destination_path,
            );
            $this->activateDestination($instance, $transfer);
            $this->checkpoint($transfer, AppInstanceTransferStep::DestinationActivated);
        }

        if ($transfer->current_step === AppInstanceTransferStep::DestinationActivated) {
            $this->projectionOwner->run(fn () => $this->cleanupSource($instance, $transfer));
        }

        return $instance->refresh()->load(['routes.targets', 'node']);
    }

    private function materializeDestination(
        TransferSourceCapture $capture,
        Node $destination,
        StoragePath $path,
        AppInstanceTransfer $transfer,
    ): void {
        $this->sources->materialize($capture, $destination, $path);
        $this->checkpoint($transfer, AppInstanceTransferStep::DestinationCheckoutCreated);
    }

    private function transferSqlite(AppInstance $instance, Node $destination, AppInstanceTransfer $transfer): void
    {
        if ($transfer->sqlite_source_path === null) {
            return;
        }

        $instance->loadMissing('node');
        $result = $this->sqlite->seed(
            new SqliteSeedPlacement(
                appInstanceId: $instance->id,
                environment: 'development',
                basePath: $instance->checkout_path,
                executionUser: $instance->node->user,
                node: $instance->node,
            ),
            new SqliteSeedPlacement(
                appInstanceId: $instance->id,
                environment: 'development',
                basePath: $transfer->destination_path,
                executionUser: $destination->user,
                node: $destination,
            ),
            $transfer->sqlite_source_path,
        );

        if (! $result->confirmed || ! is_bool($result->changed)) {
            throw $this->conflict(
                'instance.clone_sqlite_unconfirmed',
                'The destination SQLite snapshot result is unconfirmed. Retry the request.',
            );
        }
    }

    private function importEnvironment(AppInstance $instance): void
    {
        $context = $this->contexts->resolve($instance->refresh(), requireActiveNode: true);
        $imported = [];

        try {
            $imported = $this->environmentImporter->parse($this->environmentReader->read($context));
        } catch (ResourceOperationException) {
            $imported = [];
        }

        $stored = [];

        foreach (AppInstanceEnvironmentValue::query()
            ->where('app_instance_id', $instance->id)
            ->orderBy('env_key')
            ->get() as $row) {
            $stored[$row->env_key] = $row->env_value;
        }

        $toImport = array_diff_key($imported, $stored);

        if ($toImport !== []) {
            $this->environmentStore->import($context, $toImport, replace: false);
        }
    }

    private function rebuildDestinationEnvironment(
        AppInstance $instance,
        Node $destination,
        AppInstanceTransfer $transfer,
    ): void {
        $values = [];

        foreach (AppInstanceEnvironmentValue::query()
            ->where('app_instance_id', $instance->id)
            ->orderBy('env_key')
            ->get() as $row) {
            $values[$row->env_key] = $row->env_value;
        }

        if ($values === []) {
            return;
        }

        $route = Route::query()->findOrFail($transfer->destination_route_id ?? $transfer->source_route_id);
        $context = new AppInstanceEnvironmentContext(
            appInstanceId: $instance->id,
            appId: $instance->app_id,
            nodeId: $destination->id,
            environment: 'development',
            path: $transfer->destination_path,
            executionUser: $destination->user,
            laravel: $instance->source_is_laravel === true,
            routeId: $route->id,
            routeDomain: $transfer->destination_domain,
            nodeStatus: $destination->status->value,
            node: $destination,
        );
        $contents = $this->environmentRenderer->render($context, $values);
        $result = $this->environmentWriter->write($context, $contents);

        if (! $result->confirmed || ! is_bool($result->changed)) {
            throw $this->conflict(
                'env.sync_unconfirmed',
                'The destination environment write is unconfirmed. Retry the request.',
            );
        }
    }

    private function prepareRoute(AppInstance $instance, Node $destination, AppInstanceTransfer $transfer): void
    {
        $route = Route::query()->findOrFail($transfer->source_route_id);
        $placement = $this->routeState->forNode($destination);

        if ($route->domain === $transfer->destination_domain) {
            $transfer->update(['destination_route_id' => $route->id]);
            $transfer->refresh();

            return;
        }

        $replacement = Route::query()->create([
            'app_id' => $instance->app_id,
            'node_id' => $placement->nodeId,
            'cluster_id' => $placement->clusterId,
            'generation_basis_node_id' => $destination->id,
            'domain' => $transfer->destination_domain,
            'provenance' => RouteProvenance::Generated,
            'publication' => $route->publication,
            'status' => RouteStatus::Pending,
            'replaces_route_id' => $route->id,
            'replacement_step' => RouteReplacementStep::Reserved,
        ]);
        $replacement->targets()->create([
            'app_instance_id' => $instance->id,
            'position' => 0,
        ]);
        $route->update(['replaced_by_route_id' => $replacement->id]);
        $transfer->update(['destination_route_id' => $replacement->id]);
        $transfer->refresh();
    }

    private function cutover(AppInstance $instance, Node $destination, AppInstanceTransfer $transfer, ?int $sourceClusterId): void
    {
        if ($sourceClusterId === null) {
            throw $this->conflict('instance.transfer_source_router_unknown', 'The source Route requires its original Router.');
        }

        $this->projectionOwner->run(fn () => $this->cutoverOwned($instance, $destination, $transfer, $sourceClusterId));
    }

    private function cutoverOwned(AppInstance $instance, Node $destination, AppInstanceTransfer $transfer, int $sourceClusterId): void
    {
        DB::transaction(function () use ($instance, $destination, $transfer, $sourceClusterId): void {
            $lockedInstance = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
            $lockedTransfer = AppInstanceTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $sourceRoute = Route::query()->lockForUpdate()->findOrFail($lockedTransfer->source_route_id);
            $destinationRoute = Route::query()->lockForUpdate()->findOrFail(
                $lockedTransfer->destination_route_id ?? $lockedTransfer->source_route_id,
            );
            $placement = $this->routeState->forNode($destination);

            if ($sourceRoute->cluster_id !== $sourceClusterId) {
                throw $this->conflict('instance.transfer_cleanup_conflict', 'The source Route placement changed before cutover.');
            }
            $lockedTransfer->update(['source_router_node_id' => $this->sourceRouterId($sourceRoute)]);

            $lockedInstance->update([
                'node_id' => $destination->id,
                'vite_port' => (int) DB::table('vite_port_assignments')->where('app_instance_id', $instance->id)->where('node_id', $destination->id)->value('port'),
                ...($lockedInstance->agentation_port === null ? [] : [
                    'agentation_port' => app(AgentationPortAllocator::class)->nextAvailable($destination->id, $lockedInstance->id),
                ]),
                'name' => $lockedTransfer->destination_name,
                'checkout_path' => $lockedTransfer->destination_path,
                'source_layout' => AppInstanceSourceLayout::Checkout,
            ]);

            if ($destinationRoute->id === $sourceRoute->id) {
                $destinationRoute->update([
                    'node_id' => $placement->nodeId,
                    'cluster_id' => $placement->clusterId,
                    'generation_basis_node_id' => $sourceRoute->provenance === RouteProvenance::Generated
                        ? $destination->id
                        : $sourceRoute->generation_basis_node_id,
                ]);
            } else {
                $sourceRoute->update([
                    'status' => RouteStatus::Retiring,
                    'replacement_step' => RouteReplacementStep::DatabaseCutover,
                ]);
                $destinationRoute->update([
                    'status' => RouteStatus::Active,
                    'failed_step' => null,
                    'error_code' => null,
                    'replacement_step' => RouteReplacementStep::DatabaseCutover,
                ]);
            }

            $lockedTransfer->update([
                'status' => AppInstanceTransferStatus::InProgress,
                'current_step' => AppInstanceTransferStep::Cutover,
                'cutover_at' => now(),
                'failed_step' => null,
                'error_code' => null,
            ]);
        });

        $transfer->refresh();
    }

    private function activateDestination(AppInstance $instance, AppInstanceTransfer $transfer): void
    {
        $route = Route::query()->findOrFail($transfer->destination_route_id ?? $transfer->source_route_id);
        $this->projection->converge($instance->refresh()->load('node'), $route);
        $this->runtime->activate($instance);
    }

    private function cleanupSource(AppInstance $instance, AppInstanceTransfer $transfer): void
    {
        DB::transaction(fn (): array => $this->lockCleanupRoutes($instance, $transfer));
        $sourceNode = Node::query()->findOrFail($transfer->source_node_id);
        $this->transferProjection->retireSource($transfer);
        $this->runtime->cleanupSourceArtifacts($instance, $sourceNode, $transfer->source_path);
        $cleanup = $this->sources->cleanupSource($transfer);

        if (! $cleanup->sourcePlacementRemoved) {
            $transfer->update([
                'recovery_evidence' => [
                    'incomplete' => $cleanup->incomplete,
                    'source_path' => $transfer->source_path,
                    'source_node_id' => $transfer->source_node_id,
                ],
                'error_code' => 'instance.transfer_cleanup_incomplete',
            ]);
            $transfer->refresh();

            throw $this->conflict(
                'instance.transfer_cleanup_incomplete',
                'Destination is authoritative. Retry to finish old-placement cleanup.',
            );
        }

        DB::transaction(function () use ($instance, $sourceNode, $transfer): void {
            [$lockedTransfer, $sourceRoute, $destinationRoute] = $this->lockCleanupRoutes($instance, $transfer);
            if ($destinationRoute->id !== $sourceRoute->id) {
                $sourceRoute->delete();
                $destinationRoute->update([
                    'replacement_step' => null,
                    'replaces_route_id' => null,
                    'replaced_by_route_id' => null,
                    'failed_step' => null,
                    'error_code' => null,
                ]);
            }

            app(VitePortAllocator::class)->release($instance, $sourceNode);
            $this->checkpoint($lockedTransfer, AppInstanceTransferStep::Completed, [
                'status' => AppInstanceTransferStatus::Completed,
                'completed_at' => now(),
                'recovery_evidence' => null,
                'failed_step' => null,
                'error_code' => null,
            ]);
        });
    }

    private function sourceRouterId(Route $route): int
    {
        $route->load('cluster.routerAssignment.node');
        $router = $route->cluster?->routerAssignment?->node;
        if (! $router instanceof Node) {
            throw $this->conflict('instance.transfer_source_router_unknown', 'The source Route requires its original Router.');
        }

        return $router->id;
    }

    /** @return array{AppInstanceTransfer, Route, Route} */
    private function lockCleanupRoutes(AppInstance $instance, AppInstanceTransfer $transfer): array
    {
        $lockedInstance = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
        $lockedTransfer = AppInstanceTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
        $sourceRoute = Route::query()->lockForUpdate()->find($lockedTransfer->source_route_id);
        $destinationRoute = Route::query()->lockForUpdate()->find($lockedTransfer->destination_route_id);
        if (
            $lockedTransfer->app_instance_id !== $lockedInstance->id
            || $lockedTransfer->cutover_at === null
            || $lockedTransfer->completed_at !== null
            || $lockedTransfer->current_step !== AppInstanceTransferStep::DestinationActivated
            || $lockedInstance->node_id !== $lockedTransfer->destination_node_id
            || $lockedInstance->checkout_path !== $lockedTransfer->destination_path
            || ! $sourceRoute instanceof Route
            || ! $destinationRoute instanceof Route
            || $destinationRoute->status !== RouteStatus::Active
            || $destinationRoute->domain !== $lockedTransfer->destination_domain
        ) {
            throw $this->conflict('instance.transfer_cleanup_conflict', 'Transfer placement changed before source cleanup.');
        }

        foreach ([$sourceRoute, $destinationRoute] as $route) {
            $targets = $route->targets()->lockForUpdate()->pluck('app_instance_id')->all();
            if ($route->app_id !== $lockedInstance->app_id || $targets !== [$lockedInstance->id]) {
                throw $this->conflict('instance.transfer_cleanup_conflict', 'Transfer Route ownership changed before source cleanup.');
            }
        }

        $replaced = $sourceRoute->id !== $destinationRoute->id;
        if (
            $sourceRoute->replaced_by_route_id !== ($replaced ? $destinationRoute->id : null)
            || $destinationRoute->replaces_route_id !== ($replaced ? $sourceRoute->id : null)
            || $sourceRoute->replaces_route_id !== null
            || $destinationRoute->replaced_by_route_id !== null
            || $sourceRoute->status !== ($replaced ? RouteStatus::Retiring : RouteStatus::Active)
            || $sourceRoute->replacement_step !== ($replaced ? RouteReplacementStep::DatabaseCutover : null)
            || $destinationRoute->replacement_step !== ($replaced ? RouteReplacementStep::DatabaseCutover : null)
        ) {
            throw $this->conflict('instance.transfer_cleanup_conflict', 'Transfer Route lifecycle changed before source cleanup.');
        }

        return [$lockedTransfer, $sourceRoute, $destinationRoute];
    }

    /** @param array<string, mixed> $attributes */
    private function checkpoint(
        AppInstanceTransfer $transfer,
        AppInstanceTransferStep $step,
        array $attributes = [],
    ): void {
        $transfer->update([
            ...$attributes,
            'current_step' => $step,
            'status' => $step === AppInstanceTransferStep::Completed
                ? AppInstanceTransferStatus::Completed
                : AppInstanceTransferStatus::InProgress,
            'failed_step' => $attributes['failed_step'] ?? null,
            'error_code' => $attributes['error_code'] ?? null,
        ]);
        $transfer->refresh();
    }

    private function recordFailure(string $transferId, Throwable $exception): void
    {
        $transfer = AppInstanceTransfer::query()->find($transferId);

        if (! $transfer instanceof AppInstanceTransfer) {
            return;
        }

        $errorCode = property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : 'instance.transfer_failed';
        $failedStep = $transfer->current_step;

        if ($transfer->cutover_at === null) {
            $this->restoreBeforeCutover($transfer);
            $transfer->update([
                'status' => AppInstanceTransferStatus::Failed,
                'current_step' => AppInstanceTransferStep::Reserved,
                'failed_step' => $failedStep,
                'error_code' => $errorCode,
            ]);

            return;
        }

        $transfer->update([
            'status' => AppInstanceTransferStatus::Failed,
            'failed_step' => $failedStep,
            'error_code' => $errorCode,
        ]);
    }

    private function restoreBeforeCutover(AppInstanceTransfer $transfer): void
    {
        $instance = AppInstance::query()->with('node')->find($transfer->app_instance_id);

        if (! $instance instanceof AppInstance) {
            return;
        }

        $incomplete = [];

        try {
            $this->runtime->restore($instance);
        } catch (Throwable) {
            $incomplete[] = 'source-runtime';
        }

        $destination = Node::query()->find($transfer->destination_node_id);

        if ($destination instanceof Node) {
            try {
                $this->sources->discardDestination($destination, StoragePath::parse($transfer->destination_path));
                app(VitePortAllocator::class)->release($instance, $destination);
            } catch (Throwable) {
                $incomplete[] = 'destination-checkout';
            }
        }

        if (
            $transfer->destination_route_id !== null
            && $transfer->destination_route_id !== $transfer->source_route_id
        ) {
            $replacement = Route::query()->find($transfer->destination_route_id);

            if ($replacement instanceof Route && $replacement->status !== RouteStatus::Active) {
                try {
                    $replacement->targets()->delete();
                    $replacement->delete();
                    Route::query()->whereKey($transfer->source_route_id)->update(['replaced_by_route_id' => null]);
                    $transfer->update(['destination_route_id' => null]);
                } catch (Throwable) {
                    $incomplete[] = 'destination-route';
                }
            }
        }

        if ($incomplete !== []) {
            $transfer->update([
                'recovery_evidence' => [
                    'incomplete' => $incomplete,
                    'destination_path' => $transfer->destination_path,
                    'source_path' => $transfer->source_path,
                ],
            ]);
        }
    }

    private function conflict(string $errorCode, string $message): ResourceOperationException
    {
        return new ResourceOperationException($errorCode, $message, 409);
    }
}
