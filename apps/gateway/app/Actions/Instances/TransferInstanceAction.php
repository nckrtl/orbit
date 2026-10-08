<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\TransferInstanceData;
use App\Domain\AppDev\AgentationPortAllocator;
use App\Domain\AppDev\AnnotatorEndpoint;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentImporter;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Sqlite\InstanceSqliteSeeder;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Transfer\InstanceTransferRouteProjector;
use App\Domain\Instances\Transfer\InstanceTransferRuntime;
use App\Domain\Instances\Transfer\InstanceTransferSource;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Instances\Transfer\TransferSourceCapture;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Domain\Projects\ProjectApps;
use App\Domain\Routes\RoutePlacement;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\ScheduleTargetUseGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceAppProjection;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class TransferInstanceAction
{
    public function __construct(
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private InstanceDestinationGuard $destinationGuard,
        private InstanceEnvironmentOperationLock $environmentOperations,
        private AppDevSourceOperationLock $sourceLock,
        private InstanceTransferSource $sources,
        private InstanceTransferRuntime $runtime,
        private InstanceSqliteSeeder $sqlite,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentReader $environmentReader,
        private InstanceEnvironmentImporter $environmentImporter,
        private InstanceEnvironmentStore $environmentStore,
        private InstanceEnvironmentRenderer $environmentRenderer,
        private InstanceEnvironmentWriter $environmentWriter,
        private RouteStateResolver $routeState,
        private DevelopmentRouteProjector $projection,
        private InstanceTransferRouteProjector $transferProjection,
        private DevelopmentProjectionOperationLock $projectionOwner,
        private ClusterRouterOperationLock $routerOwner,
        private ScheduleTargetUseGuard $schedules,
        private ProcessAdmissionLock $processAdmissions,
    ) {}

    /** @return array{instance: Instance, transfer: InstanceTransfer, created: bool} */
    public function execute(Instance $instance, TransferInstanceData $data): array
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        InstanceAppProjection::assertAvailable([$instance->id]);
        $instance->loadMissing(['project', 'node', 'routes.targets', 'removalMember']);
        $existing = $this->existingTransfer($instance, $data);

        if ($existing instanceof InstanceTransfer && $existing->completed_at !== null) {
            return [
                'instance' => $instance->refresh()->load(['routes.targets', 'node']),
                'transfer' => $existing,
                'created' => false,
            ];
        }

        if ($existing?->cutover_at !== null) {
            return $this->executeOwned($instance, $data, $existing, null);
        }

        $clusterId = $instance->node->cluster_id;
        if (! $existing instanceof InstanceTransfer) {
            $this->requiredRoutes($instance);
        }
        if ($clusterId === null) {
            throw $this->conflict('instance.standalone_unsupported', 'The source Route must belong to an active Cluster.');
        }

        return $this->routerOwner->run(
            $clusterId,
            fn (): array => $this->executeOwned($instance, $data, $existing, $clusterId),
        );
    }

    /** @return array{instance: Instance, transfer: InstanceTransfer, created: bool} */
    private function executeOwned(
        Instance $instance,
        TransferInstanceData $data,
        ?InstanceTransfer $existing,
        ?int $sourceClusterId,
    ): array {
        if ($existing instanceof InstanceTransfer) {
            $transfer = $existing->refresh();
            $created = false;

            if ($transfer->cutover_at === null) {
                $this->schedules->assertInstanceStable($instance);
            }
        } else {
            [$transfer, $created] = $this->environmentOperations->run(
                [$instance->id],
                function () use ($instance, $data): array {
                    InstanceAppProjection::assertAvailable([$instance->id]);
                    $instance->refresh();
                    $reserved = $this->existingTransfer($instance, $data);

                    return [$reserved ?? $this->reserve($instance, $data), $reserved === null];
                },
            );
        }

        $result = $this->environmentOperations->run(
            [$instance->id],
            fn (): Instance => $this->sourceLock->synchronized(
                $transfer->destination_node_id,
                fn (): Instance => $this->sourceLock->synchronized(
                    $transfer->source_node_id,
                    function () use ($instance, $transfer, $sourceClusterId): Instance {
                        InstanceAppProjection::assertAvailable([$instance->id]);
                        try {
                            return $this->resume($instance->id, $transfer->id, $sourceClusterId);
                        } catch (Throwable $exception) {
                            $this->recordFailure($transfer->id, $exception);

                            throw $exception;
                        }
                    },
                ),
            ),
        );

        return [
            'instance' => $result,
            'transfer' => InstanceTransfer::query()->findOrFail($transfer->id),
            'created' => $created,
        ];
    }

    private function existingTransfer(Instance $instance, TransferInstanceData $data): ?InstanceTransfer
    {
        $existing = InstanceTransfer::query()
            ->where('instance_id', $instance->id)
            ->open()
            ->orderByDesc('created_at')
            ->first();

        if (! $existing instanceof InstanceTransfer) {
            $completed = InstanceTransfer::query()
                ->where('instance_id', $instance->id)
                ->whereNotNull('completed_at')
                ->orderByDesc('completed_at')
                ->first();

            if (
                $completed instanceof InstanceTransfer
                && $this->sameRequest($completed, $instance, $data)
            ) {
                return $completed;
            }

            return null;
        }

        if (! $this->sameRequest($existing, $instance, $data)) {
            throw $this->conflict(
                'instance.transfer_retry_conflict',
                'Only the identical transfer request can resume this Instance.',
            );
        }

        return $existing;
    }

    private function sameRequest(
        InstanceTransfer $transfer,
        Instance $instance,
        TransferInstanceData $data,
    ): bool {
        return $transfer->destination_node_id === $data->nodeId
            && $transfer->requested_name === $data->name
            && $transfer->sqlite_source_path === $data->sqliteSourcePath
            && $transfer->instance_id === $instance->id;
    }

    private function reserve(Instance $instance, TransferInstanceData $data): InstanceTransfer
    {
        $this->schedules->assertInstanceStable($instance);
        [$destination, $path, $journal] = $this->preflight($instance, $data);
        $sole = count($journal) === 1 ? array_first($journal) : null;

        return InstanceTransfer::query()->create([
            'instance_id' => $instance->id,
            'source_node_id' => $instance->node_id,
            'source_router_node_id' => $sole['source_router_node_id'] ?? null,
            'destination_node_id' => $destination->id,
            'requested_name' => $data->name,
            'destination_name' => $data->name ?? $instance->name,
            'destination_path' => $path->value,
            'destination_domain' => $sole['destination_domain'] ?? null,
            'app_journal' => $journal,
            'sqlite_source_path' => $data->sqliteSourcePath,
            'source_layout' => $instance->source_layout,
            'source_path' => $instance->checkout_path,
            'common_repository_path' => $instance->registration_common_repository_path,
            'source_route_id' => $sole['source_route_id'] ?? null,
            'status' => InstanceTransferStatus::Reserved,
            'current_step' => InstanceTransferStep::Reserved,
        ]);
    }

    /** @return array{Node, StoragePath, array<string, array{source_route_id: ?int, destination_route_id: ?int, destination_domain: ?string, source_router_node_id: ?int, source_app_identity: bool, imported_environment_keys: list<string>, annotator?: array{source_store: string}}> } */
    private function preflight(Instance $instance, TransferInstanceData $data): array
    {
        $instance->refresh()->loadMissing(['project', 'node', 'routes.targets']);
        $this->assertEligibleSource($instance);
        $destination = Node::query()->findOrFail($data->nodeId);
        $this->assertEligibleDestination($instance, $destination);
        $name = $data->name ?? $instance->name;
        $this->assertDestinationIdentity($instance, $name);
        $path = $this->destinationPath($destination, $instance->project->slug, $name);
        $this->assertDestinationAvailable($destination, $path);
        $routes = $this->requiredRoutes($instance);
        $placement = $this->routeState->forNode($destination);

        if ($placement->clusterId !== null) {
            $this->routeState->assertRouter($placement->clusterId);
        }

        $journal = [];
        foreach ($instance->effectiveApps() as $app) {
            $route = $routes[$app['name']] ?? null;
            $domain = $route === null ? null : $this->destinationDomain($instance, $route, $name, $placement);
            if ($route !== null && $domain !== null) {
                $this->assertDestinationDomain($instance, $route, $domain);
            }
            $journal[$app['name']] = [
                'source_route_id' => $route?->id,
                'destination_route_id' => null,
                'destination_domain' => $domain,
                'source_router_node_id' => $route === null ? null : $this->sourceRouterId($route),
                'source_app_identity' => $instance->usesAppRuntimeIdentity($app['name']),
                'imported_environment_keys' => [],
            ];
            if ($instance->processes()->where('app', $app['name'])->where('runtime_config->preset', 'annotator')->exists()) {
                $journal[$app['name']]['annotator'] = ['source_store' => AnnotatorEndpoint::forInstance($instance, $app['name'])];
            }
        }

        return [$destination, $path, $journal];
    }

    private function assertEligibleSource(Instance $instance): void
    {
        if ($instance->status !== InstanceState::Active || $instance->provisioning_step !== 'active') {
            throw $this->conflict('instance.lifecycle_conflict', 'The Instance is not active for transfer.');
        }

        if (! $instance->placedOnAppDev()) {
            throw $this->conflict(
                'instance.production_refused',
                'Transfer accepts only an active development Instance.',
            );
        }

        if ($instance->removalMember !== null) {
            throw $this->conflict('instance.removal_conflict', 'The Instance is being removed.');
        }

        $this->assertActiveAppDevCluster($instance->node, 'source');
    }

    private function assertEligibleDestination(Instance $instance, Node $destination): void
    {
        if ($destination->id === $instance->node_id) {
            throw $this->conflict('instance.same_node', 'Transfer requires a distinct destination Node.');
        }

        $this->assertActiveAppDevCluster($destination, 'destination');
        $instance->loadMissing('project');
        app(DevelopmentNodeExclusion::class)->assertAvailable($instance->project, $destination);
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

    private function assertDestinationIdentity(Instance $instance, string $name): void
    {
        $taken = Instance::query()
            ->where('project_id', $instance->project_id)
            ->where('name', $name)
            ->whereKeyNot($instance->id)
            ->exists();

        if ($taken) {
            throw $this->conflict(
                'instance.identity_conflict',
                "Instance name [{$name}] is already owned. Retry with a different name.",
            );
        }
    }

    private function destinationPath(Node $destination, string $projectSlug, string $name): StoragePath
    {
        $account = $this->accounts->resolve($destination);
        $roots = $this->storageRoots->resolveApps(
            $this->nodeSettings->fromStored($destination->settings),
            $account,
        );

        return $roots->append($projectSlug, $name);
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

    /** @return array<string, Route> */
    private function requiredRoutes(Instance $instance): array
    {
        $routes = [];
        foreach ($instance->effectiveApps() as $app) {
            if ($instance->task_workspace_routed !== false && ProjectApps::isServing($app)) {
                $routes[$app['name']] = $this->authoritativeRoute($instance, $app['name']);
            }
        }

        return $routes;
    }

    private function authoritativeRoute(Instance $instance, string $app): Route
    {
        $route = $instance->authoritativeRoute($app);

        if (! $route instanceof Route) {
            throw $this->conflict('instance.lifecycle_conflict', 'The Instance has no authoritative Route.');
        }

        if ($instance->routes->where('app', $app)->count() !== 1
            || $route->failed_step !== null || $route->error_code !== null || $route->replacement_step !== null
            || $route->status !== RouteStatus::Active || $route->hasPlacementTransition()
            || $route->project_id !== $instance->project_id
            || $route->targets->pluck('instance_id')->all() !== [$instance->id]
            || $route->targets->pluck('app')->all() !== [$app]
            || $route->cluster_id !== $instance->node->cluster_id
            || $route->replaced_by_route_id !== null || $route->replaces_route_id !== null) {
            throw $this->conflict(
                'instance.lifecycle_conflict',
                'Transfer cannot start while a Route domain change is incomplete.',
            );
        }

        return $route;
    }

    private function destinationDomain(
        Instance $instance,
        Route $route,
        string $name,
        RoutePlacement $placement,
    ): string {
        if ($route->provenance === RouteProvenance::Explicit) {
            return $route->domain;
        }

        return $this->routeState->generatedDomain($instance->project->slug, $name, $placement->effectiveTld, $route->app ?? throw $this->conflict('instance.lifecycle_conflict', 'An app Route must record its app.'));
    }

    private function assertDestinationDomain(Instance $instance, Route $route, string $domain): void
    {
        $taken = Route::query()
            ->where('domain', $domain)
            ->whereKeyNot($route->id)
            ->exists();

        if ($taken) {
            throw $this->conflict('route.domain_conflict', "Route domain [{$domain}] is already owned.");
        }
    }

    private function resume(int $instanceId, string $transferId, ?int $sourceClusterId): Instance
    {
        $instance = Instance::query()->with(['project', 'node', 'routes.targets'])->findOrFail($instanceId);
        $transfer = InstanceTransfer::query()->findOrFail($transferId);
        $destination = Node::query()->findOrFail($transfer->destination_node_id);
        $path = StoragePath::parse($transfer->destination_path);
        $names = array_column($instance->effectiveApps(), 'name');
        $journalNames = array_keys($transfer->app_journal ?? []);
        sort($names);
        sort($journalNames);
        if ($names !== $journalNames) {
            throw $this->conflict('instance.lifecycle_conflict', 'The transfer journal must cover every effective app.');
        }

        if ($transfer->cutover_at === null && $transfer->current_step === InstanceTransferStep::SourceCaptured) {
            $transfer->update(['recovery_evidence' => [...($transfer->recovery_evidence ?? []), 'rollback_pending' => true]]);
        }
        if ($transfer->cutover_at === null && ($transfer->recovery_evidence['rollback_pending'] ?? false) === true) {
            $this->restoreBeforeCutover($transfer, resuming: true);
            $transfer->refresh();

            if (($transfer->recovery_evidence['rollback_pending'] ?? false) === true) {
                throw $this->conflict('instance.transfer_failed', 'Transfer rollback is incomplete. Retry the identical request.');
            }
        } elseif ($transfer->status === InstanceTransferStatus::Failed && $transfer->cutover_at === null && $this->needsSqliteSeedCleanup($transfer)) {
            $this->abandonSqliteSeed($instance, $destination, $transfer);
        }

        if ($transfer->cutover_at === null) {
            $this->reservePorts($instance, $destination, $transfer);
        }
        if ($transfer->current_step === InstanceTransferStep::Reserved) {
            $this->runtime->pause($instance);
            $capture = $this->sources->capture($instance, $transfer->sqlite_source_path);
            $this->checkpoint($transfer, InstanceTransferStep::SourceCaptured, [
                'common_repository_path' => $capture->commonRepositoryPath ?? $transfer->common_repository_path,
            ]);
            $this->materializeDestination($capture, $destination, $path, $transfer);
        }

        if ($transfer->current_step === InstanceTransferStep::DestinationCheckoutCreated) {
            $this->checkpoint($transfer, InstanceTransferStep::SourcePaused);
        }

        if ($transfer->current_step === InstanceTransferStep::SourcePaused) {
            $this->transferSqlite($instance, $destination, $transfer);
            $this->checkpoint($transfer, InstanceTransferStep::SqliteTransferred);
        }

        if ($transfer->current_step === InstanceTransferStep::SqliteTransferred) {
            $this->importEnvironment($instance, $transfer);
            $this->checkpoint($transfer, InstanceTransferStep::EnvironmentImported);
        }

        if ($transfer->current_step === InstanceTransferStep::EnvironmentImported) {
            $this->rebuildDestinationEnvironment($instance, $destination, $transfer);
            $this->checkpoint($transfer, InstanceTransferStep::EnvironmentRebuilt);
        }

        if ($transfer->current_step === InstanceTransferStep::EnvironmentRebuilt) {
            $this->checkpoint($transfer, InstanceTransferStep::RuntimeRelocated);
        }

        if ($transfer->current_step === InstanceTransferStep::RuntimeRelocated) {
            $this->prepareRoute($instance, $destination, $transfer);
            $this->checkpoint($transfer, InstanceTransferStep::RoutePrepared);
        }

        if ($transfer->current_step === InstanceTransferStep::RoutePrepared) {
            $this->processAdmissions->run(
                [$instance->id],
                function () use ($instance, $destination, $transfer, $sourceClusterId): void {
                    $this->schedules->assertInstanceStable($instance->refresh());
                    $this->cutover($instance, $destination, $transfer, $sourceClusterId);
                },
            );
        }

        $instance = Instance::query()->with(['project', 'node', 'routes.targets'])->findOrFail($instanceId);
        $transfer = InstanceTransfer::query()->findOrFail($transferId);

        if ($transfer->current_step === InstanceTransferStep::Cutover) {
            $this->runtime->relocate(
                $instance,
                $destination,
                $transfer->source_path,
                $transfer->destination_path,
            );
            $this->activateDestination($instance, $transfer);
            $this->checkpoint($transfer, InstanceTransferStep::DestinationActivated);
        }

        if ($transfer->current_step === InstanceTransferStep::DestinationActivated) {
            $this->projectionOwner->run(fn () => $this->cleanupSource($instance, $transfer));
        }

        return $instance->refresh()->load(['routes.targets', 'node']);
    }

    private function materializeDestination(
        TransferSourceCapture $capture,
        Node $destination,
        StoragePath $path,
        InstanceTransfer $transfer,
    ): void {
        $this->sources->materialize($capture, $destination, $path);
        $this->checkpoint($transfer, InstanceTransferStep::DestinationCheckoutCreated);
    }

    private function transferSqlite(Instance $instance, Node $destination, InstanceTransfer $transfer): void
    {
        if ($transfer->sqlite_source_path === null) {
            return;
        }

        [$sourcePlacement, $targetPlacement] = $this->sqlitePlacements($instance, $destination, $transfer);
        $result = $this->sqlite->seed($sourcePlacement, $targetPlacement, $transfer->sqlite_source_path);

        if (! $result->confirmed || ! is_bool($result->changed)) {
            throw $this->conflict(
                'instance.clone_sqlite_unconfirmed',
                'The destination SQLite snapshot result is unconfirmed. Retry the request.',
            );
        }
    }

    /** @return array{SqliteSeedPlacement, SqliteSeedPlacement} */
    private function sqlitePlacements(Instance $instance, Node $destination, InstanceTransfer $transfer): array
    {
        $instance->loadMissing('node');

        return [
            new SqliteSeedPlacement(
                instanceId: $instance->id,
                environment: 'development',
                basePath: $transfer->source_path,
                executionUser: $instance->node->user,
                node: $instance->node,
            ),
            new SqliteSeedPlacement(
                instanceId: $instance->id,
                environment: 'development',
                basePath: $transfer->destination_path,
                executionUser: $destination->user,
                node: $destination,
                operationId: $transfer->id,
            ),
        ];
    }

    private function needsSqliteSeedCleanup(InstanceTransfer $transfer): bool
    {
        $incomplete = $transfer->recovery_evidence['incomplete'] ?? [];

        return $transfer->sqlite_source_path !== null && (
            $transfer->current_step->rank() >= InstanceTransferStep::SourcePaused->rank()
            || ($transfer->failed_step?->rank() ?? -1) >= InstanceTransferStep::SourcePaused->rank()
            || (is_array($incomplete) && in_array('sqlite-seed', $incomplete, true))
        );
    }

    private function abandonSqliteSeed(Instance $instance, Node $destination, InstanceTransfer $transfer): void
    {
        if ($transfer->sqlite_source_path === null) {
            return;
        }

        [$sourcePlacement, $targetPlacement] = $this->sqlitePlacements($instance, $destination, $transfer);
        if (! $this->sqlite->abandon($sourcePlacement, $targetPlacement, $transfer->sqlite_source_path)) {
            throw $this->conflict('instance.transfer_failed', 'The unfinished SQLite seed could not be cleaned safely. Retry the identical request.');
        }
    }

    private function importEnvironment(Instance $instance, InstanceTransfer $transfer): void
    {
        foreach ($transfer->app_journal ?? [] as $app => $entry) {
            $context = $this->contexts->resolve($instance->refresh(), requireActiveNode: true, app: $app);
            $imported = $this->environmentImporter->parse($this->environmentReader->read($context));
            $stored = [];
            foreach (InstanceEnvironmentValue::query()->where('instance_id', $instance->id)->where('app', $app)->get() as $row) {
                $stored[$row->env_key] = $row->env_value;
            }
            $toImport = array_diff_key($imported, $stored);
            if ($toImport !== []) {
                $journal = $transfer->app_journal ?? [];
                $journal[$app] = [...$entry, 'imported_environment_keys' => array_values(array_unique([...$entry['imported_environment_keys'], ...array_keys($toImport)]))];
                $this->saveJournal($transfer, $journal);
                $this->environmentStore->import($context, $toImport, replace: false);
            }
        }
    }

    private function rebuildDestinationEnvironment(
        Instance $instance,
        Node $destination,
        InstanceTransfer $transfer,
    ): void {
        foreach ($transfer->app_journal ?? [] as $app => $entry) {
            $values = [];

            foreach (InstanceEnvironmentValue::query()
                ->where('instance_id', $instance->id)->where('app', $app)
                ->orderBy('env_key')
                ->get() as $row) {
                $values[$row->env_key] = $row->env_value;
            }

            $routeId = $entry['destination_route_id'] ?? $entry['source_route_id'];
            $context = new InstanceEnvironmentContext(
                instanceId: $instance->id,
                projectId: $instance->project_id,
                nodeId: $destination->id,
                environment: 'development',
                path: ApplicationDirectory::resolvePath($transfer->destination_path, $instance->applicationPath($app)),
                executionUser: $destination->user,
                laravel: $instance->runtimeForApp($app)['laravel'] === true,
                routeId: $routeId,
                routeDomain: $entry['destination_domain'],
                nodeStatus: $destination->status->value,
                node: $destination,
                app: $app,
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
    }

    private function prepareRoute(Instance $instance, Node $destination, InstanceTransfer $transfer): void
    {
        DB::transaction(function () use ($instance, $destination, $transfer): void {
            $journal = $transfer->app_journal ?? [];
            $placement = $this->routeState->forNode($destination);
            foreach ($journal as $app => &$entry) {
                if ($entry['source_route_id'] === null || $entry['destination_route_id'] !== null) {
                    continue;
                }
                $route = Route::query()->lockForUpdate()->findOrFail($entry['source_route_id']);
                if ($route->domain === $entry['destination_domain']) {
                    $entry['destination_route_id'] = $route->id;

                    continue;
                }
                $replacement = Route::query()->create([
                    'project_id' => $instance->project_id,
                    'app' => $app,
                    'node_id' => $placement->nodeId,
                    'cluster_id' => $placement->clusterId,
                    'generation_basis_node_id' => $destination->id,
                    'domain' => $entry['destination_domain'],
                    'provenance' => RouteProvenance::Generated,
                    'publication' => $route->publication,
                    'status' => RouteStatus::Pending,
                    'replaces_route_id' => $route->id,
                    'replacement_step' => RouteReplacementStep::Reserved,
                ]);
                $replacement->targets()->create(['instance_id' => $instance->id, 'app' => $app, 'position' => 0]);
                $route->update(['replaced_by_route_id' => $replacement->id]);
                $entry['destination_route_id'] = $replacement->id;
            }
            unset($entry);
            $this->saveJournal($transfer, $journal);
        });
    }

    private function cutover(Instance $instance, Node $destination, InstanceTransfer $transfer, ?int $sourceClusterId): void
    {
        if ($sourceClusterId === null) {
            throw $this->conflict('instance.transfer_source_router_unknown', 'The source Route requires its original Router.');
        }

        $this->projectionOwner->run(fn () => $this->cutoverOwned($instance, $destination, $transfer, $sourceClusterId));
    }

    private function cutoverOwned(Instance $instance, Node $destination, InstanceTransfer $transfer, int $sourceClusterId): void
    {
        DB::transaction(function () use ($instance, $destination, $transfer, $sourceClusterId): void {
            $lockedInstance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            Node::query()->whereKey($destination->id)->lockForUpdate()->firstOrFail();
            $lockedTransfer = InstanceTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $placement = $this->routeState->forNode($destination);
            $journal = $lockedTransfer->app_journal ?? [];
            $runtime = $lockedInstance->app_runtime ?? [];
            foreach ($lockedInstance->effectiveApps() as $app) {
                $entry = $journal[$app['name']] ?? throw $this->conflict('instance.lifecycle_conflict', 'The transfer journal must cover every app.');
                $runtime[$app['name']] = [...($runtime[$app['name']] ?? []), ...($entry['ports'] ?? []), 'app_identity' => true, 'app_identity_ready' => false, 'vite_environment_identity' => true, 'annotator_store_identity' => true];
            }
            $sole = count($runtime) === 1 ? array_first($runtime) : [];
            $lockedInstance->update([
                'node_id' => $destination->id,
                'app_runtime' => $runtime,
                ...($sole === [] ? [] : ['vite_port' => $sole['vite_port'] ?? null, 'annotator_port' => $sole['annotator_port'] ?? null, 'agentation_port' => $sole['agentation_port'] ?? null]),
                'name' => $lockedTransfer->destination_name,
                'checkout_path' => $lockedTransfer->destination_path,
                'source_layout' => InstanceSourceLayout::Checkout,
            ]);
            foreach ($journal as $app => $entry) {
                if ($entry['source_route_id'] === null) {
                    continue;
                }
                $sourceRoute = Route::query()->lockForUpdate()->findOrFail($entry['source_route_id']);
                $destinationRoute = Route::query()->lockForUpdate()->findOrFail($entry['destination_route_id']);
                foreach ([$sourceRoute, $destinationRoute] as $route) {
                    if ($route->app !== $app || $route->project_id !== $lockedInstance->project_id || $route->targets()->lockForUpdate()->pluck('instance_id')->all() !== [$instance->id] || $route->targets()->lockForUpdate()->pluck('app')->all() !== [$app]) {
                        throw $this->conflict('instance.transfer_cleanup_conflict', 'App Route ownership changed before cutover.');
                    }
                }
                if ($sourceRoute->cluster_id !== $sourceClusterId || $sourceRoute->status !== RouteStatus::Active
                    || $sourceRoute->hasPlacementTransition() || $sourceRoute->replaces_route_id !== null
                    || $sourceRoute->failed_step !== null || $sourceRoute->error_code !== null
                    || $destinationRoute->domain !== $entry['destination_domain']
                    || $entry['source_router_node_id'] !== $this->sourceRouterId($sourceRoute)
                    || $destinationRoute->replaces_route_id !== ($sourceRoute->id === $destinationRoute->id ? null : $sourceRoute->id)
                    || $destinationRoute->status !== ($sourceRoute->id === $destinationRoute->id ? RouteStatus::Active : RouteStatus::Pending)
                    || $sourceRoute->replaced_by_route_id !== ($sourceRoute->id === $destinationRoute->id ? null : $destinationRoute->id)) {
                    throw $this->conflict('instance.transfer_cleanup_conflict', 'The source Route placement changed before cutover.');
                }
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

            }
            $lockedTransfer->update([
                'status' => InstanceTransferStatus::InProgress,
                'current_step' => InstanceTransferStep::Cutover,
                'cutover_at' => now(),
                'failed_step' => null,
                'error_code' => null,
            ]);
        });

        $transfer->refresh();
    }

    private function activateDestination(Instance $instance, InstanceTransfer $transfer): void
    {
        $this->sources->verifyDestination($transfer);
        foreach ($transfer->app_journal ?? [] as $entry) {
            if ($entry['destination_route_id'] !== null) {
                $route = Route::query()->findOrFail($entry['destination_route_id']);
                $this->projection->converge($instance->refresh()->load('node'), $route);
            }
        }
        $this->runtime->activate($instance);
    }

    private function cleanupSource(Instance $instance, InstanceTransfer $transfer): void
    {
        DB::transaction(fn (): array => $this->lockCleanupRoutes($instance, $transfer));
        $this->sources->verifyDestination($transfer);
        $sourceNode = Node::query()->findOrFail($transfer->source_node_id);
        $this->transferProjection->retireSource($transfer);
        $annotationPorts = app(AgentationPortAllocator::class);
        foreach ($transfer->app_journal ?? [] as $app => $entry) {
            $annotationPorts->releaseOnNode($instance, $sourceNode->id, 'annotator_port', $app);
            $annotationPorts->releaseOnNode($instance, $sourceNode->id, 'agentation_port', $app);
        }
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
            [$lockedTransfer, $pairs] = $this->lockCleanupRoutes($instance, $transfer);
            foreach ($pairs as [$sourceRoute, $destinationRoute]) {
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

            }
            app(VitePortAllocator::class)->release($instance, $sourceNode);
            $this->checkpoint($lockedTransfer, InstanceTransferStep::Completed, [
                'status' => InstanceTransferStatus::Completed,
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

    /** @return array{InstanceTransfer, list<array{Route, Route}>} */
    private function lockCleanupRoutes(Instance $instance, InstanceTransfer $transfer): array
    {
        $lockedInstance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
        $lockedTransfer = InstanceTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
        $pairs = [];
        if (
            $lockedTransfer->instance_id !== $lockedInstance->id
            || $lockedTransfer->cutover_at === null
            || $lockedTransfer->completed_at !== null
            || $lockedTransfer->current_step !== InstanceTransferStep::DestinationActivated
            || $lockedInstance->node_id !== $lockedTransfer->destination_node_id
            || $lockedInstance->checkout_path !== $lockedTransfer->destination_path
            || $lockedTransfer->app_journal === null

        ) {
            throw $this->conflict('instance.transfer_cleanup_conflict', 'Transfer placement changed before source cleanup.');
        }

        $placement = $this->routeState->forNode($lockedInstance->node);
        foreach ($lockedTransfer->app_journal ?? [] as $app => $entry) {
            if ($entry['source_route_id'] === null) {
                continue;
            }
            $sourceRoute = Route::query()->lockForUpdate()->find($entry['source_route_id']);
            $destinationRoute = Route::query()->lockForUpdate()->find($entry['destination_route_id']);
            if (! $sourceRoute instanceof Route || ! $destinationRoute instanceof Route || $sourceRoute->app !== $app || $destinationRoute->app !== $app || $destinationRoute->status !== RouteStatus::Active || $destinationRoute->domain !== $entry['destination_domain'] || $destinationRoute->cluster_id !== $placement->clusterId || $destinationRoute->node_id !== $placement->nodeId) {
                throw $this->conflict('instance.transfer_cleanup_conflict', 'App Route ownership changed before source cleanup.');
            }
            foreach ([$sourceRoute, $destinationRoute] as $route) {
                $targets = $route->targets()->lockForUpdate()->pluck('instance_id')->all();
                if ($route->project_id !== $lockedInstance->project_id || $targets !== [$lockedInstance->id] || $route->targets()->lockForUpdate()->pluck('app')->all() !== [$app]) {
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

            $pairs[] = [$sourceRoute, $destinationRoute];
        }

        return [$lockedTransfer, $pairs];
    }

    /** @param array<string, mixed> $attributes */
    private function checkpoint(
        InstanceTransfer $transfer,
        InstanceTransferStep $step,
        array $attributes = [],
    ): void {
        $transfer->update([
            ...$attributes,
            'current_step' => $step,
            'status' => $step === InstanceTransferStep::Completed
                ? InstanceTransferStatus::Completed
                : InstanceTransferStatus::InProgress,
            'failed_step' => $attributes['failed_step'] ?? null,
            'error_code' => $attributes['error_code'] ?? null,
        ]);
        $transfer->refresh();
    }

    private function recordFailure(string $transferId, Throwable $exception): void
    {
        $transfer = InstanceTransfer::query()->find($transferId);

        if (! $transfer instanceof InstanceTransfer) {
            return;
        }

        $errorCode = property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : 'instance.transfer_failed';
        $failedStep = $transfer->current_step;

        if ($transfer->cutover_at === null) {
            $rollbackPending = ($transfer->recovery_evidence['rollback_pending'] ?? false) === true;
            $transfer->update([
                'status' => InstanceTransferStatus::Failed,
                'failed_step' => $rollbackPending ? ($transfer->failed_step ?? $failedStep) : $failedStep,
                'error_code' => $rollbackPending ? ($transfer->error_code ?? $errorCode) : $errorCode,
                'recovery_evidence' => [...($transfer->recovery_evidence ?? []), 'rollback_pending' => true],
            ]);
            $this->restoreBeforeCutover($transfer);

            return;
        }

        $transfer->update([
            'status' => InstanceTransferStatus::Failed,
            'failed_step' => $failedStep,
            'error_code' => $errorCode,
        ]);
    }

    private function restoreBeforeCutover(InstanceTransfer $transfer, bool $resuming = false): void
    {
        $instance = Instance::query()->with('node')->find($transfer->instance_id);

        if (! $instance instanceof Instance) {
            return;
        }

        $incomplete = [];
        $destination = Node::query()->find($transfer->destination_node_id);

        if ($this->needsSqliteSeedCleanup($transfer)) {
            try {
                if (! $destination instanceof Node) {
                    throw $this->conflict('instance.transfer_failed', 'The SQLite seed destination is unavailable for cleanup.');
                }
                $this->abandonSqliteSeed($instance, $destination, $transfer);
            } catch (Throwable) {
                $incomplete[] = 'sqlite-seed';
            }
        }

        $sourceRestored = false;
        try {
            $this->runtime->restore($instance);
            $sourceRestored = true;
        } catch (Throwable) {
            $incomplete[] = 'source-runtime';
        }

        if ($destination instanceof Node && $sourceRestored) {
            try {
                $this->sources->discardDestination($destination, StoragePath::parse($transfer->destination_path));
                app(VitePortAllocator::class)->release($instance, $destination);
            } catch (Throwable) {
                $incomplete[] = 'destination-checkout';
            }
        }

        $journal = $transfer->app_journal ?? [];
        foreach ($journal as $app => &$entry) {
            if ($destination instanceof Node && $sourceRestored) {
                app(AgentationPortAllocator::class)->releaseOnNode($instance, $destination->id, 'annotator_port', $app);
                app(AgentationPortAllocator::class)->releaseOnNode($instance, $destination->id, 'agentation_port', $app);
            }
            if (
                $entry['destination_route_id'] !== null
                && $entry['destination_route_id'] !== $entry['source_route_id']
            ) {
                $replacement = Route::query()->find($entry['destination_route_id']);

                if (! $replacement instanceof Route || $replacement->status !== RouteStatus::Active) {
                    try {
                        if ($replacement instanceof Route) {
                            if ($replacement->project_id !== $instance->project_id || $replacement->app !== $app
                                || $replacement->replaces_route_id !== $entry['source_route_id']
                                || $replacement->targets()->pluck('instance_id')->all() !== [$instance->id]
                                || $replacement->targets()->pluck('app')->all() !== [$app]) {
                                throw $this->conflict('instance.transfer_cleanup_conflict', 'The prepared app Route no longer belongs to this transfer.');
                            }
                            $replacement->targets()->delete();
                            $replacement->delete();
                        }
                        Route::query()
                            ->whereKey($entry['source_route_id'])
                            ->where('replaced_by_route_id', $entry['destination_route_id'])
                            ->update(['replaced_by_route_id' => null]);
                        $entry['destination_route_id'] = null;
                    } catch (Throwable) {
                        $incomplete[] = 'destination-route';
                    }
                } else {
                    $incomplete[] = 'destination-route';
                }
            }

            $importedKeys = $entry['imported_environment_keys'];

            if ($importedKeys !== []) {
                try {
                    InstanceEnvironmentValue::query()
                        ->where('instance_id', $instance->id)
                        ->where('app', $app)->whereIn('env_key', $importedKeys)
                        ->delete();
                    $entry['imported_environment_keys'] = [];
                } catch (Throwable) {
                    $incomplete[] = 'imported-environment';
                }
            }

            if ($incomplete === []) {
                $entry['destination_route_id'] = null;
                unset($entry['ports']);
                if (isset($entry['annotator'])) {
                    $entry['annotator'] = ['source_store' => $entry['annotator']['source_store']];
                }
            }
        }
        unset($entry);
        $this->saveJournal($transfer, $journal);
        $transfer->update([
            'status' => $resuming && $incomplete === [] ? InstanceTransferStatus::InProgress : InstanceTransferStatus::Failed,
            'current_step' => InstanceTransferStep::Reserved,
            'recovery_evidence' => $incomplete === [] ? null : [
                'rollback_pending' => true,
                'incomplete' => $incomplete,
                'destination_path' => $transfer->destination_path,
                'source_path' => $transfer->source_path,
            ],
        ]);
    }

    /** @param array<string, array{source_route_id: ?int, destination_route_id: ?int, destination_domain: ?string, source_router_node_id: ?int, source_app_identity?: bool, imported_environment_keys: list<string>, annotator?: array{source_store: string, archive?: string, attempt?: string, restored_store?: string, staging_store?: string, ownership_receipt?: string}, ports?: array<string, int>}> $journal */
    private function saveJournal(InstanceTransfer $transfer, array $journal): void
    {
        $sole = count($journal) === 1 ? array_first($journal) : null;
        $transfer->update([
            'app_journal' => $journal,
            'destination_route_id' => $sole['destination_route_id'] ?? null,
            'imported_environment_keys' => $sole['imported_environment_keys'] ?? [],
        ]);
        $transfer->refresh();
    }

    private function reservePorts(Instance $instance, Node $destination, InstanceTransfer $transfer): void
    {
        DB::transaction(function () use ($instance, $destination, $transfer): void {
            Node::query()->whereKey($destination->id)->lockForUpdate()->firstOrFail();
            $journal = $transfer->app_journal ?? [];
            $allocator = app(AgentationPortAllocator::class);
            foreach ($journal as $app => &$entry) {
                $vite = app(VitePortAllocator::class);
                $vite->assign($instance, app: $app);
                $entry['ports']['vite_port'] = $vite->assign($instance, $destination, app: $app) ?? 0;
                foreach (['annotator_port', 'agentation_port'] as $kind) {
                    $allocator->retain($instance, $kind, $app);
                    if (($instance->runtimeForApp($app)[$kind] ?? null) === null) {
                        continue;
                    }
                    $recorded = DB::table('annotation_port_assignments')->where('instance_id', $instance->id)->where('node_id', $destination->id)->where('app', $app)->where('kind', $kind)->value('port');
                    $port = $recorded === null ? $allocator->nextAvailable($destination->id, $instance->id, $kind, app: $app) : StoredInteger::from($recorded);
                    DB::table('annotation_port_assignments')->updateOrInsert(['instance_id' => $instance->id, 'node_id' => $destination->id, 'app' => $app, 'kind' => $kind], ['port' => $port]);
                    $entry['ports'][$kind] = $port;
                }
            }
            unset($entry);
            $this->saveJournal($transfer, $journal);
        });
    }

    private function conflict(string $errorCode, string $message): ResourceOperationException
    {
        return new ResourceOperationException($errorCode, $message, 409);
    }
}
