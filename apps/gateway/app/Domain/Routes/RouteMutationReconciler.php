<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteTarget;

/**
 * @mago-expect lint:cyclomatic-complexity The reconciler validates the complete closed Route proposal before any write.
 * @mago-expect lint:kan-defect Atomic reconciliation keeps every fail-closed proposal branch in one domain boundary.
 */
final readonly class RouteMutationReconciler
{
    public function __construct(
        private RouteStateResolver $state,
    ) {}

    /**
     * The caller owns the surrounding transaction and infrastructure locks.
     *
     * @param array<int, array{tld?: ?string, cluster_id?: ?int}> $nodeOverrides
     * @param array<int, array{tld?: ?string, state?: ClusterState}> $clusterOverrides
     * @param array<int, array{tld?: ?string, cluster_id?: ?int}> $baselineNodeOverrides
     * @param array<int, array{tld?: ?string, state?: ClusterState}> $baselineClusterOverrides
     */
    public function reconcile(
        array $nodeOverrides = [],
        array $clusterOverrides = [],
        array $baselineNodeOverrides = [],
        array $baselineClusterOverrides = [],
    ): void {
        [$routes, $proposals] = $this->proposals(
            $nodeOverrides,
            $clusterOverrides,
            $baselineNodeOverrides,
            $baselineClusterOverrides,
        );

        foreach ($routes as $route) {
            $proposal = $proposals[$route->id];
            $changed = array_filter(
                $proposal,
                static fn (mixed $value, string $key): bool => $route->getAttribute($key) !== $value,
                ARRAY_FILTER_USE_BOTH,
            );

            if ($changed !== [] && $route->status === RouteStatus::Active) {
                new RouteReconciliationGuard()->refuse();
            }

            $route->update($proposal);
        }
    }

    /**
     * @param array<int, array{tld?: ?string, cluster_id?: ?int}> $nodeOverrides
     * @param array<int, array{tld?: ?string, state?: ClusterState}> $clusterOverrides
     */
    public function validate(array $nodeOverrides = [], array $clusterOverrides = []): void
    {
        $this->proposals($nodeOverrides, $clusterOverrides, [], []);
    }

    /**
     * @param array<int, array{tld?: ?string, cluster_id?: ?int}> $nodeOverrides
     * @param array<int, array{tld?: ?string, state?: ClusterState}> $clusterOverrides
     * @param array<int, array{tld?: ?string, cluster_id?: ?int}> $baselineNodeOverrides
     * @param array<int, array{tld?: ?string, state?: ClusterState}> $baselineClusterOverrides
     * @return array{\Illuminate\Database\Eloquent\Collection<int, Route>, array<int, array{node_id: ?int, cluster_id: ?int, generation_basis_node_id: ?int, hostname: string}>}
     */
    private function proposals(
        array $nodeOverrides,
        array $clusterOverrides,
        array $baselineNodeOverrides,
        array $baselineClusterOverrides,
    ): array {
        $routes = Route::query()
            ->with(['app', 'targets.appInstance.node', 'generationBasisNode'])
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
        $proposals = [];
        $hostnames = [];

        foreach ($routes as $route) {
            $proposal = $this->proposal(
                $route,
                $nodeOverrides,
                $clusterOverrides,
                $baselineNodeOverrides,
                $baselineClusterOverrides,
            );
            $owner = $hostnames[$proposal['hostname']] ?? null;

            if ($owner !== null && $owner !== $route->id) {
                throw new ResourceOperationException(
                    errorCode: 'route.hostname_conflict',
                    message: "Route hostname [{$proposal['hostname']}] would collide.",
                    status: 409,
                );
            }

            $hostnames[$proposal['hostname']] = $route->id;
            $proposals[$route->id] = $proposal;
        }

        return [$routes, $proposals];
    }

    /**
     * @param array<int, array{tld?: ?string, cluster_id?: ?int}> $nodeOverrides
     * @param array<int, array{tld?: ?string, state?: ClusterState}> $clusterOverrides
     * @param array<int, array{tld?: ?string, cluster_id?: ?int}> $baselineNodeOverrides
     * @param array<int, array{tld?: ?string, state?: ClusterState}> $baselineClusterOverrides
     * @return array{node_id: ?int, cluster_id: ?int, generation_basis_node_id: ?int, hostname: string}
     */
    private function proposal(
        Route $route,
        array $nodeOverrides,
        array $clusterOverrides,
        array $baselineNodeOverrides,
        array $baselineClusterOverrides,
    ): array {
        $targets = $route->targets;
        $placement = null;
        $firstTarget = null;

        foreach ($targets as $targetRow) {
            assert($targetRow instanceof RouteTarget);
            $target = $targetRow->appInstance;
            $this->assertTarget($route, $target);
            $targetPlacement = $this->state->forNode($target->node, $nodeOverrides, $clusterOverrides);

            if (
                $placement instanceof RoutePlacement
                && ($placement->nodeId !== $targetPlacement->nodeId
                || $placement->clusterId !== $targetPlacement->clusterId)
            ) {
                throw new ResourceOperationException(
                    errorCode: 'route.target_scope_conflict',
                    message: "Route [{$route->hostname}] targets would span routing scopes.",
                    status: 409,
                );
            }

            $placement = $targetPlacement;
            $firstTarget ??= $target;
        }

        if (! $placement instanceof RoutePlacement) {
            $placement = $this->placementWithoutTarget($route, $nodeOverrides, $clusterOverrides);
        }

        if ($placement->clusterId !== null) {
            $this->state->assertRouter($placement->clusterId);
        }

        $hostname = $route->hostname;

        if ($route->provenance === RouteProvenance::Generated) {
            $hostname = $firstTarget instanceof AppInstance
                ? $this->state->generatedHostname(
                    $route->app->slug,
                    (string) $route->app->main_branch,
                    $firstTarget->name,
                    $placement->effectiveTld,
                )
                : $this->rebaseRetainedHostname(
                    $route,
                    $placement,
                    $baselineNodeOverrides,
                    $baselineClusterOverrides,
                );
        }

        return [
            'node_id' => $placement->nodeId,
            'cluster_id' => $placement->clusterId,
            'generation_basis_node_id' => $route->generation_basis_node_id,
            'hostname' => RouteHostname::validate($hostname),
        ];
    }

    /**
     * @param array<int, array{tld?: ?string, cluster_id?: ?int}> $nodeOverrides
     * @param array<int, array{tld?: ?string, state?: ClusterState}> $clusterOverrides
     */
    private function placementWithoutTarget(Route $route, array $nodeOverrides, array $clusterOverrides): RoutePlacement
    {
        if ($route->provenance === RouteProvenance::Generated) {
            $basis = $route->generationBasisNode;

            if (! $basis instanceof Node) {
                throw new ResourceOperationException(
                    errorCode: 'route.generation_basis_missing',
                    message: "Generated Route [{$route->hostname}] has no generation basis.",
                    status: 409,
                );
            }

            return $this->state->forNode($basis, $nodeOverrides, $clusterOverrides);
        }

        if ($route->node_id !== null) {
            return $this->state->forNode(
                Node::query()->findOrFail($route->node_id),
                $nodeOverrides,
                $clusterOverrides,
            );
        }

        $cluster = Cluster::query()->findOrFail((int) $route->cluster_id);
        $override = $clusterOverrides[$cluster->id] ?? [];
        $state = $override['state'] ?? $cluster->state;

        if ($state !== ClusterState::Active) {
            throw new ResourceOperationException(
                errorCode: 'route.scope_invalid',
                message: "Targetless Route [{$route->hostname}] cannot leave its Cluster scope.",
                status: 409,
            );
        }

        return new RoutePlacement(nodeId: null, clusterId: $cluster->id, effectiveTld: null);
    }

    private function assertTarget(Route $route, AppInstance $target): void
    {
        if ($target->app_id !== $route->app_id || $target->status !== AppInstanceState::Active) {
            throw new ResourceOperationException(
                errorCode: 'route.target_invalid',
                message: "Route [{$route->hostname}] has an invalid target.",
                status: 409,
            );
        }
    }

    /**
     * @param array<int, array{tld?: ?string, cluster_id?: ?int}> $baselineNodeOverrides
     * @param array<int, array{tld?: ?string, state?: ClusterState}> $baselineClusterOverrides
     */
    private function rebaseRetainedHostname(
        Route $route,
        RoutePlacement $proposedPlacement,
        array $baselineNodeOverrides,
        array $baselineClusterOverrides,
    ): string {
        $basis = $route->generationBasisNode;
        assert($basis instanceof Node);
        $currentTld = $this->state
            ->forNode($basis, $baselineNodeOverrides, $baselineClusterOverrides)
            ->effectiveTld;
        $proposedTld = $proposedPlacement->effectiveTld;

        if ($proposedTld === null) {
            throw new ResourceOperationException(
                errorCode: 'route.tld_required',
                message: "Generated Route [{$route->hostname}] would have no effective TLD.",
                status: 409,
            );
        }

        if ($currentTld === $proposedTld) {
            return $route->hostname;
        }

        if ($currentTld === null || ! str_ends_with($route->hostname, ".{$currentTld}")) {
            throw new ResourceOperationException(
                errorCode: 'route.generation_basis_invalid',
                message: "Generated Route [{$route->hostname}] cannot be reconciled from its stored basis.",
                status: 409,
            );
        }

        return substr($route->hostname, 0, -strlen($currentTld)).$proposedTld;
    }
}
