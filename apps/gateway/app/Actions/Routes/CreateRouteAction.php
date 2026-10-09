<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Data\Routes\CreateRouteData;
use App\Data\Routes\RouteData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionCloneRouteProjector;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Instances\ProductionWebRootManager;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Routes\CustomProxyProcessListener;
use App\Domain\Routes\CustomProxyRouteProjector;
use App\Domain\Routes\CustomProxyUpstream;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Routes\RouteAssociationGuard;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteTargetWebRoot;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteCustomProxy;
use App\Models\RouteTarget;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CreateRouteAction
{
    public function __construct(
        private RouteStateResolver $state,
        private RouteAssociationGuard $associations,
        private CustomProxyRouteProjector $customProxies,
        private CustomProxyProcessListener $listeners = new CustomProxyProcessListener,
        private ?RecordEventBroadcaster $broadcaster = null,
        private ?MetricsFleetReconciler $metrics = null,
        private ?DevelopmentRouteProjector $developmentRoutes = null,
        private ?ProductionRouteProjector $productionRoutes = null,
        private ?ProductionCloneRouteProjector $productionCloneRoutes = null,
        private ?DevelopmentProjectionOperationLock $projectionOwner = null,
        private ?PublishPublicRouteAction $publishPublic = null,
        private ?SynchronizeRouteWebRootUrlsAction $webRootUrls = null,
        private ?ProductionWebRootManager $productionWebRoots = null,
    ) {}

    /** @return array{route: Route, created: bool} */
    public function execute(CreateRouteData $data): array
    {
        return $this->run($data);
    }

    /** @return array{route: Route, created: bool} */
    public function executeForRouteCreate(CreateRouteData $data): array
    {
        if ($data->isCustomProxy()) {
            return $this->run($data);
        }

        if ($data->instanceId === null || $data->projectId !== null || $data->nodeId !== null || $data->clusterId !== null) {
            throw new ResourceOperationException('route.scope_required', 'An app Route requires an Instance and no explicit Project or scope.');
        }

        return ($this->projectionOwner ?? app(DevelopmentProjectionOperationLock::class))->run(
            fn (): array => $this->activateExplicitRoute($data),
        );
    }

    /** @return array{route: Route, created: bool} */
    private function activateExplicitRoute(CreateRouteData $data): array
    {
        $instance = Instance::query()->with(['project', 'node'])->findOrFail($data->instanceId);
        $result = $this->run(new CreateRouteData(
            domain: $data->domain,
            publication: $data->publication,
            projectId: $instance->project_id,
            instanceId: $instance->id,
            webRoot: $data->webRoot,
        ), activating: true);

        if (! $result['created']) {
            if ($result['route']->status === RouteStatus::Active) {
                return $result;
            }

            // Only this creation path stores Activating before touching the serving path.
            // Older pending Routes remain unchanged and are not adopted on retry.
            if ($result['route']->status !== RouteStatus::Activating) {
                throw new ResourceOperationException('route.activation_unsupported', 'The existing Route is not active.', 409);
            }
        }

        $route = $result['route'];
        if ($instance->placedOnAppProd()) {
            $projection = $this->productionRoutes ?? app(ProductionRouteProjector::class);
            $projection->prepareCertificate($instance, $route);

            if ($route->hasWebRoot()) {
                // The selected release gets the web root's .env link and Caddy access, and its stable .env
                // its APP_URL, before the pool of its directory starts.
                ($this->productionWebRoots ?? app(ProductionWebRootManager::class))->prepare($instance);
                ($this->webRootUrls ?? app(SynchronizeRouteWebRootUrlsAction::class))->execute($instance);
            }

            $projection->prepareRuntime($instance, $route);
            $projection->prepareFirewall($instance);
            $steps = $this->productionCloneRoutes ?? app(ProductionCloneRouteProjector::class);
            $steps->prepareWorkloadCaddy($instance, $route);
            $steps->prepareRouterCertificate($instance, $route);
            $steps->prepareRouteFirewall($instance, $route);
            $steps->verifyWorkload($instance, $route);
            $steps->prepareRouterCaddy($instance, $route);
            $steps->prepareDns($route);
        } else {
            ($this->developmentRoutes ?? app(DevelopmentRouteProjector::class))->converge($instance, $route);

            if ($route->hasWebRoot()) {
                ($this->webRootUrls ?? app(SynchronizeRouteWebRootUrlsAction::class))->execute($instance);
            }
        }
        if ($data->publication === RoutePublication::Public) {
            $route = ($this->publishPublic ?? app(PublishPublicRouteAction::class))->execute($route, RoutePublication::Public);
        }

        $route->update(['status' => RouteStatus::Active]);

        return ['route' => $route->refresh()->load('targets'), 'created' => $result['created']];
    }

    /** @return array{route: Route, created: bool} */
    private function run(CreateRouteData $data, bool $activating = false): array
    {
        $domain = RouteDomain::validate($data->domain);
        ReservedPrivateHostname::assertAvailable($domain);

        $result = $data->isCustomProxy()
            ? $this->persistCustomProxy($data, $domain)
            : $this->persistExplicit($data, $domain, $activating);

        if ($result['created']) {
            ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
                RecordEventType::RouteCreated,
                $result['route']->id,
                RouteData::fromModel($result['route'])->toArray(),
            );

            if ($result['route']->publication === RoutePublication::Public) {
                $this->metrics?->reconcile();
            }
        }

        return $result;
    }

    public function ensureForInstance(Instance $instance, ?string $domain): Route
    {
        $instance->refresh()->loadMissing(['project', 'node']);

        if (! in_array(
            $instance->status,
            [
                InstanceState::Reserved,
                InstanceState::CheckoutPrepared,
                InstanceState::SourceResolved,
                InstanceState::Active,
            ],
            true,
        )) {
            throw new ResourceOperationException(
                errorCode: 'route.instance_inactive',
                message: 'A Route can be created only during Instance provisioning or after activation.',
                status: 409,
            );
        }

        $existing = Route::query()
            ->whereNull('web_root')
            ->whereHas('targets', static fn ($query) => $query->where('instance_id', $instance->id))
            ->first();

        if ($existing instanceof Route) {
            $expectedProvenance = $domain === null ? RouteProvenance::Generated : RouteProvenance::Explicit;
            $normalized = $domain === null ? null : RouteDomain::validate($domain);

            if (
                $existing->provenance !== $expectedProvenance
                || $normalized !== null
                && $existing->domain !== $normalized
            ) {
                throw new ResourceOperationException(
                    errorCode: 'route.retry_conflict',
                    message: 'The Instance Route already exists with different immutable input.',
                    status: 409,
                );
            }

            return $existing->load('targets');
        }

        $placement = $this->state->forNode($instance->node);

        if ($placement->clusterId !== null) {
            $this->state->assertRouter($placement->clusterId);
        }

        $provenance = $domain === null ? RouteProvenance::Generated : RouteProvenance::Explicit;
        $resolvedHostname = $domain === null
            ? $this->state->generatedDomain(
                $instance->project->slug,
                $instance->name,
                $placement->effectiveTld,
            )
            : RouteDomain::validate($domain);

        return $this->create(
            projectId: $instance->project_id,
            domain: $resolvedHostname,
            publication: RoutePublication::Private,
            provenance: $provenance,
            nodeId: $placement->nodeId,
            clusterId: $placement->clusterId,
            generationBasisNodeId: $provenance === RouteProvenance::Generated ? $instance->node_id : null,
            instance: $instance,
        );
    }

    /** @return array{route: Route, created: bool} */
    private function persistCustomProxy(CreateRouteData $data, string $domain): array
    {
        if ($data->nodeId === null || $data->projectId !== null || $data->instanceId !== null || $data->clusterId !== null) {
            throw new ResourceOperationException(
                errorCode: 'route.scope_required',
                message: 'A custom proxy Route requires a serving Node and no Project target.',
            );
        }

        if (($data->upstream === null) === ($data->processId === null)) {
            throw new ResourceOperationException(
                errorCode: 'route.upstream_invalid',
                message: 'Supply exactly one custom proxy upstream or Process.',
            );
        }

        $node = Node::query()->findOrFail($data->nodeId);

        if ($node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('route.node_inactive', 'The Route Node must be active.', 409);
        }

        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException(
                errorCode: 'route.node_inactive',
                message: 'The Route Node must have a managed WireGuard address.',
                status: 409,
            );
        }

        $process = null;
        $upstream = $data->upstream === null
            ? null
            : CustomProxyUpstream::parse($data->upstream);

        if ($data->processId !== null) {
            $process = Process::query()->findOrFail($data->processId);

            if ($process->owner_type !== Node::class || $process->owner_id !== $node->id) {
                throw new ResourceOperationException(
                    errorCode: 'route.process_conflict',
                    message: "Process [{$process->name}] is not a Node Process on the serving Node.",
                    status: 409,
                );
            }

            $upstream = $this->listeners->resolve($process);
        }

        assert($upstream instanceof CustomProxyUpstream);

        $existing = Route::query()->where('domain', $domain)->first();

        if ($existing instanceof Route) {
            if (! $existing->isCustomProxy()) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_conflict',
                    message: "Route domain [{$domain}] is already owned.",
                    status: 409,
                );
            }

            $this->assertIdenticalCustomProxyRetry($existing, $node->id, $process?->id, $upstream);

            return ['route' => $existing->load(['targets', 'customProxy']), 'created' => false];
        }

        try {
            $route = DB::transaction(function () use ($domain, $node, $process, $upstream): Route {
                $route = Route::query()->create([
                    'kind' => RouteKind::CustomProxy,
                    'project_id' => null,
                    'node_id' => $node->id,
                    'cluster_id' => null,
                    'generation_basis_node_id' => null,
                    'domain' => $domain,
                    'provenance' => RouteProvenance::Explicit,
                    'publication' => RoutePublication::Private,
                    'status' => RouteStatus::Pending,
                    'failed_step' => null,
                    'error_code' => null,
                ]);
                $route->customProxy()->create([
                    'node_id' => $node->id,
                    'process_id' => $process?->id,
                    'upstream' => $upstream->url(),
                ]);

                return $route->load(['targets', 'customProxy']);
            });
        } catch (QueryException $exception) {
            throw $this->conflictFromCreateFailure($domain, null, $exception);
        }

        try {
            $this->customProxies->converge($route);
            $route->update(['status' => RouteStatus::Active]);
        } catch (Throwable $exception) {
            // A failed creation clears the publication record, so the next build withdraws the site.
            $route->update([
                'status' => RouteStatus::Failed,
                'sites_published' => false,
                'failed_step' => 'projection',
                'error_code' => property_exists($exception, 'errorCode') && is_string($exception->errorCode)
                    ? $exception->errorCode
                    : 'route.projection_failed',
            ]);

            throw $exception;
        }

        return ['route' => $route->refresh()->load(['targets', 'customProxy']), 'created' => true];
    }

    private function assertIdenticalCustomProxyRetry(
        Route $existing,
        int $nodeId,
        ?int $processId,
        CustomProxyUpstream $upstream,
    ): void {
        $existing->load('customProxy');
        $proxy = $existing->customProxy;

        if (
            ! $existing->isCustomProxy()
            || ! $proxy instanceof RouteCustomProxy
            || $existing->node_id !== $nodeId
            || $proxy->process_id !== $processId
            || $proxy->upstream !== $upstream->url()
        ) {
            throw new ResourceOperationException(
                errorCode: 'route.retry_conflict',
                message: 'The Route domain already exists with conflicting intent.',
                status: 409,
            );
        }
    }

    /** @return array{route: Route, created: bool} */
    private function persistExplicit(CreateRouteData $data, string $domain, bool $activating): array
    {
        if ($data->projectId === null) {
            throw new ResourceOperationException(
                errorCode: 'route.scope_required',
                message: 'A Project Route requires a Project.',
            );
        }

        Project::query()->findOrFail($data->projectId);
        if ($data->instanceId === null || $data->nodeId !== null || $data->clusterId !== null) {
            throw new ResourceOperationException('route.scope_required', 'An app Route requires an Instance and derives its scope from it.');
        }

        $target = Instance::query()->with('node')->findOrFail($data->instanceId);
        $this->assertTarget($target, $data->projectId);
        $webRoot = RouteWebRoot::normalize($data->webRoot);

        if ($webRoot === null) {
            RouteTargetWebRoot::assertSupported($target);
        }

        $placement = $this->state->forNode($target->node);
        $nodeId = $placement->nodeId;
        $clusterId = $placement->clusterId;

        if ($clusterId !== null) {
            $this->state->assertRouter($clusterId);
        }

        $existing = Route::query()->where('domain', $domain)->first();

        if ($existing instanceof Route) {
            $this->assertIdenticalRetry($existing, $data, $nodeId, $clusterId, $target);

            return ['route' => $existing->load(['targets', 'customProxy']), 'created' => false];
        }

        return [
            'route' => $this->create(
                projectId: $data->projectId,
                domain: $domain,
                publication: $data->publication,
                provenance: RouteProvenance::Explicit,
                nodeId: $nodeId,
                clusterId: $clusterId,
                generationBasisNodeId: null,
                instance: $target,
                initialStatus: $activating ? RouteStatus::Activating : RouteStatus::Pending,
                webRoot: $webRoot,
            ),
            'created' => true,
        ];
    }

    private function create(
        int $projectId,
        string $domain,
        RoutePublication $publication,
        RouteProvenance $provenance,
        ?int $nodeId,
        ?int $clusterId,
        ?int $generationBasisNodeId,
        Instance $instance,
        RouteStatus $initialStatus = RouteStatus::Pending,
        ?string $webRoot = null,
    ): Route {
        if ($webRoot === null) {
            RouteTargetWebRoot::assertSupported($instance);
        }

        try {
            $route = DB::transaction(function () use (
                $projectId,
                $domain,
                $publication,
                $provenance,
                $nodeId,
                $clusterId,
                $generationBasisNodeId,
                $instance,
                $initialStatus,
                $webRoot,
            ): Route {
                // A Route with a web root is an additional site of the Instance; only the Instance's own
                // Route is unique.
                if ($webRoot === null) {
                    $this->associations->assertTargetUnassociated($instance);
                }

                $route = Route::query()->create([
                    'kind' => RouteKind::App,
                    'project_id' => $projectId,
                    'node_id' => $nodeId,
                    'cluster_id' => $clusterId,
                    'generation_basis_node_id' => $generationBasisNodeId,
                    'domain' => $domain,
                    'web_root' => $webRoot,
                    'provenance' => $provenance,
                    'publication' => $publication,
                    'status' => RouteStatus::Pending,
                    'failed_step' => null,
                    'error_code' => null,
                ]);

                $route
                    ->targets()
                    ->create([
                        'instance_id' => $instance->id,
                        'position' => 0,
                    ]);

                if ($initialStatus !== RouteStatus::Pending) {
                    $route->update(['status' => $initialStatus]);
                }

                return $route->load(['targets', 'customProxy']);
            });

            return $route;
        } catch (QueryException $exception) {
            throw $this->conflictFromCreateFailure($domain, $instance, $exception);
        }
    }

    private function assertTarget(Instance $target, int $projectId): void
    {
        if ($target->project_id !== $projectId) {
            throw new ResourceOperationException(
                errorCode: 'route.target_app_conflict',
                message: 'The Route target must belong to the Route Project.',
                status: 409,
            );
        }

        if ($target->status !== InstanceState::Active) {
            throw new ResourceOperationException(
                errorCode: 'route.target_inactive',
                message: 'The Route target must be active.',
                status: 409,
            );
        }
    }

    private function assertIdenticalRetry(
        Route $existing,
        CreateRouteData $data,
        ?int $nodeId,
        ?int $clusterId,
        ?Instance $target,
    ): void {
        $existing->load('targets');
        $existingTargetId = $existing->targets->first()?->instance_id;

        if (! $existing->isApp()) {
            throw new ResourceOperationException(
                errorCode: 'route.domain_conflict',
                message: "Route domain [{$existing->domain}] is already owned.",
                status: 409,
            );
        }

        if (
            $existing->project_id !== $data->projectId
            || $existing->publication !== $data->publication
            || $existing->provenance !== RouteProvenance::Explicit
            || $existing->node_id !== $nodeId
            || $existing->cluster_id !== $clusterId
            || $existingTargetId !== $target?->id
            || $existing->web_root !== $data->webRoot
        ) {
            throw new ResourceOperationException(
                errorCode: 'route.retry_conflict',
                message: 'The Route domain already exists with conflicting intent.',
                status: 409,
            );
        }
    }

    private function conflictFromCreateFailure(
        string $domain,
        ?Instance $instance,
        QueryException $exception,
    ): ResourceOperationException {
        if (
            $instance instanceof Instance
            && ! Route::query()->where('domain', $domain)->exists()
        ) {
            $association = RouteTarget::query()
                ->where('instance_id', $instance->id)
                ->first();

            if ($association instanceof RouteTarget) {
                return new ResourceOperationException(
                    errorCode: 'route.target_conflict',
                    message: "Instance [{$instance->id}] is already associated with Route [{$association->route_id}].",
                    status: 409,
                    previous: $exception,
                );
            }
        }

        return new ResourceOperationException(
            errorCode: 'route.domain_conflict',
            message: "Route domain [{$domain}] is already owned.",
            status: 409,
            previous: $exception,
        );
    }
}
