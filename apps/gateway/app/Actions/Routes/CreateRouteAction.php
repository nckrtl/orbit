<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Data\Routes\CreateRouteData;
use App\Domain\AppInstances\AppInstanceActivationHook;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\RouteHostname;
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** @mago-expect lint:cyclomatic-complexity Route creation validates each explicit and generated invariant before one atomic write. */
final readonly class CreateRouteAction implements AppInstanceActivationHook
{
    public function __construct(
        private RouteStateResolver $state,
    ) {}

    /** @return array{route: Route, created: bool} */
    public function execute(CreateRouteData $data): array
    {
        $hostname = RouteHostname::validate($data->hostname);

        return $this->persistExplicit($data, $hostname);
    }

    public function ensureForAppInstance(AppInstance $appInstance, ?string $hostname): Route
    {
        $appInstance->refresh()->loadMissing(['app', 'node']);

        if ($appInstance->status !== AppInstanceState::Active) {
            throw new ResourceOperationException(
                errorCode: 'route.instance_inactive',
                message: 'A Route can be created only after AppInstance activation.',
                status: 409,
            );
        }

        $existing = Route::query()
            ->whereHas('targets', static fn ($query) => $query->where('app_instance_id', $appInstance->id))
            ->first();

        if ($existing instanceof Route) {
            $expectedProvenance = $hostname === null ? RouteProvenance::Generated : RouteProvenance::Explicit;
            $normalized = $hostname === null ? null : RouteHostname::validate($hostname);

            if (
                $existing->provenance !== $expectedProvenance
                || $normalized !== null
                && $existing->hostname !== $normalized
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

        $provenance = $hostname === null ? RouteProvenance::Generated : RouteProvenance::Explicit;
        $resolvedHostname = $hostname === null
            ? $this->state->generatedHostname(
                $appInstance->app->slug,
                (string) $appInstance->app->main_branch,
                $appInstance->name,
                $placement->effectiveTld,
            )
            : RouteHostname::validate($hostname);

        return $this->create(
            appId: $appInstance->app_id,
            hostname: $resolvedHostname,
            publication: RoutePublication::Private,
            provenance: $provenance,
            nodeId: $placement->nodeId,
            clusterId: $placement->clusterId,
            generationBasisNodeId: $provenance === RouteProvenance::Generated ? $appInstance->node_id : null,
            appInstance: $appInstance,
        );
    }

    public function complete(AppInstance $appInstance, ?string $requestedName): void
    {
        $this->ensureForAppInstance($appInstance, $requestedName);
    }

    /** @return array{route: Route, created: bool} */
    private function persistExplicit(CreateRouteData $data, string $hostname): array
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

        $existing = Route::query()->where('hostname', $hostname)->first();

        if ($existing instanceof Route) {
            $this->assertIdenticalRetry($existing, $data, $nodeId, $clusterId, $target);

            return ['route' => $existing->load('targets'), 'created' => false];
        }

        return [
            'route' => $this->create(
                appId: $data->appId,
                hostname: $hostname,
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

    /** @mago-expect lint:excessive-parameter-list Atomic persistence receives the complete validated Route proposal. */
    private function create(
        int $appId,
        string $hostname,
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
                $hostname,
                $publication,
                $provenance,
                $nodeId,
                $clusterId,
                $generationBasisNodeId,
                $appInstance,
            ): Route {
                $route = Route::query()->create([
                    'app_id' => $appId,
                    'node_id' => $nodeId,
                    'cluster_id' => $clusterId,
                    'generation_basis_node_id' => $generationBasisNodeId,
                    'hostname' => $hostname,
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
            throw new ResourceOperationException(
                errorCode: 'route.hostname_conflict',
                message: "Route hostname [{$hostname}] is already owned.",
                status: 409,
                previous: $exception,
            );
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
                message: 'The Route hostname already exists with conflicting intent.',
                status: 409,
            );
        }
    }
}
