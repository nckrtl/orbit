<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Data\Routes\CreateRouteData;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\RouteAssociationGuard;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteTarget;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class CreateRouteAction
{
    public function __construct(
        private RouteStateResolver $state,
        private RouteAssociationGuard $associations,
    ) {}

    /** @return array{route: Route, created: bool} */
    public function execute(CreateRouteData $data): array
    {
        $domain = RouteDomain::validate($data->domain);

        return $this->persistExplicit($data, $domain);
    }

    public function ensureForAppInstance(AppInstance $appInstance, ?string $domain): Route
    {
        $appInstance->refresh()->loadMissing(['app', 'node']);

        if (! in_array(
            $appInstance->status,
            [
                AppInstanceState::Reserved,
                AppInstanceState::CheckoutPrepared,
                AppInstanceState::SourceResolved,
                AppInstanceState::Active,
            ],
            true,
        )) {
            throw new ResourceOperationException(
                errorCode: 'route.instance_inactive',
                message: 'A Route can be created only during AppInstance provisioning or after activation.',
                status: 409,
            );
        }

        $existing = Route::query()
            ->whereHas('targets', static fn ($query) => $query->where('app_instance_id', $appInstance->id))
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
                    message: 'The AppInstance Route already exists with different immutable input.',
                    status: 409,
                );
            }

            return $existing->load('targets');
        }

        $placement = $this->state->forNode($appInstance->node);

        if ($placement->clusterId !== null) {
            $this->state->assertRouter($placement->clusterId);
        }

        $provenance = $domain === null ? RouteProvenance::Generated : RouteProvenance::Explicit;
        $resolvedHostname = $domain === null
            ? $this->state->generatedDomain(
                $appInstance->app->slug,
                $appInstance->name,
                $placement->effectiveTld,
            )
            : RouteDomain::validate($domain);

        return $this->create(
            appId: $appInstance->app_id,
            domain: $resolvedHostname,
            publication: RoutePublication::Private,
            provenance: $provenance,
            nodeId: $placement->nodeId,
            clusterId: $placement->clusterId,
            generationBasisNodeId: $provenance === RouteProvenance::Generated ? $appInstance->node_id : null,
            appInstance: $appInstance,
        );
    }

    /** @return array{route: Route, created: bool} */
    private function persistExplicit(CreateRouteData $data, string $domain): array
    {
        OrbitApp::query()->findOrFail($data->appId);
        $target = $data->appInstanceId === null
            ? null
            : AppInstance::query()->with('node')->findOrFail($data->appInstanceId);

        if ($target instanceof AppInstance) {
            $this->assertTarget($target, $data->appId);
            $placement = $this->state->forNode($target->node);
            $nodeId = $placement->nodeId;
            $clusterId = $placement->clusterId;
        } else {
            $nodeId = $data->nodeId;
            $clusterId = $data->clusterId;
            $this->assertSuppliedScope($nodeId, $clusterId);
        }

        if ($clusterId !== null) {
            $this->state->assertRouter($clusterId);
        }

        $existing = Route::query()->where('domain', $domain)->first();

        if ($existing instanceof Route) {
            $this->assertIdenticalRetry($existing, $data, $nodeId, $clusterId, $target);

            return ['route' => $existing->load('targets'), 'created' => false];
        }

        return [
            'route' => $this->create(
                appId: $data->appId,
                domain: $domain,
                publication: $data->publication,
                provenance: RouteProvenance::Explicit,
                nodeId: $nodeId,
                clusterId: $clusterId,
                generationBasisNodeId: null,
                appInstance: $target,
            ),
            'created' => true,
        ];
    }

    private function create(
        int $appId,
        string $domain,
        RoutePublication $publication,
        RouteProvenance $provenance,
        ?int $nodeId,
        ?int $clusterId,
        ?int $generationBasisNodeId,
        ?AppInstance $appInstance,
    ): Route {
        try {
            /** @var Route $route */
            $route = DB::transaction(function () use (
                $appId,
                $domain,
                $publication,
                $provenance,
                $nodeId,
                $clusterId,
                $generationBasisNodeId,
                $appInstance,
            ): Route {
                if ($appInstance instanceof AppInstance) {
                    $this->associations->assertTargetUnassociated($appInstance);
                }

                $route = Route::query()->create([
                    'app_id' => $appId,
                    'node_id' => $nodeId,
                    'cluster_id' => $clusterId,
                    'generation_basis_node_id' => $generationBasisNodeId,
                    'domain' => $domain,
                    'provenance' => $provenance,
                    'publication' => $publication,
                    'status' => RouteStatus::Pending,
                    'failed_step' => null,
                    'error_code' => null,
                ]);

                if ($appInstance instanceof AppInstance) {
                    $route
                        ->targets()
                        ->create([
                            'app_instance_id' => $appInstance->id,
                            'position' => 0,
                        ]);
                }

                return $route->load('targets');
            });

            return $route;
        } catch (QueryException $exception) {
            throw $this->conflictFromCreateFailure($domain, $appInstance, $exception);
        }
    }

    private function assertTarget(AppInstance $target, int $appId): void
    {
        if ($target->app_id !== $appId) {
            throw new ResourceOperationException(
                errorCode: 'route.target_app_conflict',
                message: 'The Route target must belong to the Route App.',
                status: 409,
            );
        }

        if ($target->status !== AppInstanceState::Active) {
            throw new ResourceOperationException(
                errorCode: 'route.target_inactive',
                message: 'The Route target must be active.',
                status: 409,
            );
        }
    }

    private function assertSuppliedScope(?int $nodeId, ?int $clusterId): void
    {
        if (($nodeId === null) === ($clusterId === null)) {
            throw new ResourceOperationException(
                errorCode: 'route.scope_required',
                message: 'A targetless Route requires exactly one Node or Cluster scope.',
            );
        }

        if ($nodeId !== null) {
            $node = Node::query()->findOrFail($nodeId);

            if ($node->status !== LifecycleStatus::Active) {
                throw new ResourceOperationException('route.node_inactive', 'The Route Node must be active.', 409);
            }

            return;
        }

        $cluster = Cluster::query()->findOrFail((int) $clusterId);

        if ($cluster->state->value !== 'active') {
            throw new ResourceOperationException('route.cluster_inactive', 'The Route Cluster must be active.', 409);
        }
    }

    private function assertIdenticalRetry(
        Route $existing,
        CreateRouteData $data,
        ?int $nodeId,
        ?int $clusterId,
        ?AppInstance $target,
    ): void {
        $existing->load('targets');
        $existingTargetId = $existing->targets->first()?->app_instance_id;

        if (
            $existing->app_id !== $data->appId
            || $existing->publication !== $data->publication
            || $existing->provenance !== RouteProvenance::Explicit
            || $existing->node_id !== $nodeId
            || $existing->cluster_id !== $clusterId
            || $existingTargetId !== $target?->id
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
        ?AppInstance $appInstance,
        QueryException $exception,
    ): ResourceOperationException {
        if (
            $appInstance instanceof AppInstance
            && ! Route::query()->where('domain', $domain)->exists()
        ) {
            $association = RouteTarget::query()
                ->where('app_instance_id', $appInstance->id)
                ->first();

            if ($association instanceof RouteTarget) {
                return new ResourceOperationException(
                    errorCode: 'route.target_conflict',
                    message: "AppInstance [{$appInstance->id}] is already associated with Route [{$association->route_id}].",
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
