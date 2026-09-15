<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Actions\Routes\ConvergeRouteAction;
use App\Data\Clusters\UpdateClusterData;
use App\Domain\AppDev\ClusterRouterDnsSelectionReconciler;
use App\Domain\Clusters\ActiveTldScopeGuard;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Routes\RouteMutationReconciler;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class UpdateClusterAction
{
    public function __construct(
        private ActiveTldScopeGuard $tldScope,
        private ClusterRouterOperationLock $routerOperations,
        private ?RouteMutationReconciler $routes = null,
        private ?RouterLanIngressReconciler $lanIngress = null,
        private ?ClusterRouterDnsSelectionReconciler $dnsSelection = null,
        private ?ConvergeRouteAction $convergeRoute = null,
    ) {}

    public function execute(Cluster $cluster, UpdateClusterData $data): Cluster
    {
        if (! $data->tldProvided && ! $data->stateProvided) {
            return $this->update($cluster->id, $data);
        }

        return $this->routerOperations->run(
            $cluster->id,
            fn (): Cluster => $this->update($cluster->id, $data),
        );
    }

    private function update(int $clusterId, UpdateClusterData $data): Cluster
    {
        $current = Cluster::query()->findOrFail($clusterId);
        $stateChanging = $data->stateProvided
            && $data->state instanceof ClusterState
            && $data->state !== $current->state;

        $proposedTld = $data->tldProvided ? $data->tld : $current->tld;
        $proposedState = $data->state ?? $current->state;
        $tldChanging = $data->tldProvided && $data->tld !== $current->tld;
        $selectionChanging = $data->tldProvided || $stateChanging;

        if ($stateChanging) {
            $this->lanIngress()->expand(
                clusterOverrides: [$clusterId => ['state' => $data->state]],
                clusterIds: [$clusterId],
            );
        }

        try {
            if ($selectionChanging) {
                $this->dnsSelection()->expand(
                    clusterOverrides: [$clusterId => [
                        'tld' => $proposedTld,
                        'state' => $proposedState,
                    ]],
                    clusterIds: [$clusterId],
                );
            }
        } catch (Throwable $exception) {
            if ($stateChanging) {
                $this->lanIngress()->prune(clusterIds: [$clusterId]);
            }

            throw $exception;
        }

        try {
            if ($stateChanging && ! $tldChanging) {
                $this->convergeActivePrivateRoutes($current, $proposedState, $proposedTld);
            }

            /**
             * @var Cluster $updated
             */
            $updated = DB::transaction(function () use ($clusterId, $data): Cluster {
                $locked = Cluster::query()->lockForUpdate()->findOrFail($clusterId);
                $proposedTld = $data->tldProvided ? $data->tld : $locked->tld;
                $proposedState = $data->state ?? $locked->state;

                $this->tldScope->assertClusterTldAvailable($locked, $proposedTld, $proposedState);

                if ($proposedState === ClusterState::Active && $proposedTld !== null) {
                    $hasActiveRouter = $locked
                        ->routerAssignment()
                        ->whereHas('node', static fn ($query) => $query->where('status', LifecycleStatus::Active))
                        ->exists();

                    if (! $hasActiveRouter) {
                        throw new ResourceOperationException(
                            errorCode: 'cluster.router_required',
                            message: "Cluster [{$locked->name}] requires one active Router.",
                            status: 409,
                        );
                    }
                }

                $updates = [];

                if ($data->nameProvided) {
                    $updates['name'] = $data->name;
                }

                if ($data->tldProvided) {
                    $updates['tld'] = $data->tld;
                }

                if ($data->stateProvided) {
                    $updates['state'] = $data->state;
                }

                if ($proposedTld !== $locked->tld || $proposedState !== $locked->state) {
                    $this->routeReconciler()->reconcile(clusterOverrides: [
                        $locked->id => ['tld' => $proposedTld, 'state' => $proposedState],
                    ]);
                }

                $locked->update($updates);

                return $locked->refresh();
            });
        } catch (Throwable $exception) {
            if ($selectionChanging) {
                $this->dnsSelection()->prune(clusterIds: [$clusterId]);
            }

            if ($stateChanging) {
                $this->lanIngress()->prune(clusterIds: [$clusterId]);
            }

            throw $exception;
        }

        if ($selectionChanging) {
            $this->dnsSelection()->prune(clusterIds: [$clusterId]);
        }

        if ($stateChanging) {
            $this->lanIngress()->prune(clusterIds: [$clusterId]);
        }

        return $updated;
    }

    private function convergeActivePrivateRoutes(
        Cluster $cluster,
        ClusterState $proposedState,
        ?string $proposedTld,
    ): void {
        $overrides = [$cluster->id => ['tld' => $proposedTld, 'state' => $proposedState]];
        $reconciler = $this->routeReconciler();
        $reconciler->validate(clusterOverrides: $overrides);

        foreach ($reconciler->activePrivatePlacementChanges(clusterOverrides: $overrides) as $change) {
            $this->convergeRoute()->execute(
                $change['route'],
                $change['domain'],
                allowGenerated: $change['route']->provenance === RouteProvenance::Generated,
                placement: $change['placement'],
            );
        }
    }

    private function routeReconciler(): RouteMutationReconciler
    {
        return $this->routes ?? app(RouteMutationReconciler::class);
    }

    private function convergeRoute(): ConvergeRouteAction
    {
        return $this->convergeRoute ?? app(ConvergeRouteAction::class);
    }

    private function lanIngress(): RouterLanIngressReconciler
    {
        return $this->lanIngress ?? app(RouterLanIngressReconciler::class);
    }

    private function dnsSelection(): ClusterRouterDnsSelectionReconciler
    {
        return $this->dnsSelection ?? app(ClusterRouterDnsSelectionReconciler::class);
    }
}
