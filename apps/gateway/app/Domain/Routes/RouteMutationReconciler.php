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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class RouteMutationReconciler
{
    public function __construct(
        private RouteStateResolver $state,
    ) {}

    /**
     * The caller owns the surrounding transaction and infrastructure locks.
     *
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $nodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $clusterOverrides
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $baselineNodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $baselineClusterOverrides
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

            if (array_key_exists('domain', $changed)) {
                $this->replacePendingGenerated($route, $proposal);

                continue;
            }

            $route->update($proposal);
        }
    }

    /**
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $nodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $clusterOverrides
     */
    public function validate(array $nodeOverrides = [], array $clusterOverrides = []): void
    {
        $this->proposals($nodeOverrides, $clusterOverrides, [], []);
    }

    /**
     * Inventory and validate every affected Route without writing records.
     *
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $nodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $clusterOverrides
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $baselineNodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $baselineClusterOverrides
     * @return list<array{route: Route, domain: string}>
     */
    public function generatedPrivateDomainChanges(
        array $nodeOverrides = [],
        array $clusterOverrides = [],
        array $baselineNodeOverrides = [],
        array $baselineClusterOverrides = [],
    ): array {
        [$routes, $proposals] = $this->proposals(
            $nodeOverrides,
            $clusterOverrides,
            $baselineNodeOverrides,
            $baselineClusterOverrides,
        );
        $changes = [];

        foreach ($routes as $route) {
            $domain = $proposals[$route->id]['domain'];

            if (
                $route->status === RouteStatus::Active
                && $route->provenance === RouteProvenance::Generated
                && $route->publication === RoutePublication::Private
                && $route->domain !== $domain
            ) {
                $changes[] = ['route' => $route, 'domain' => $domain];
            }
        }

        return $changes;
    }

    /**
     * Inventory active private Routes whose domain or routing scope would change.
     *
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $nodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $clusterOverrides
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $baselineNodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $baselineClusterOverrides
     * @return list<array{route: Route, domain: string, placement: RoutePlacement}>
     */
    public function activePrivatePlacementChanges(
        array $nodeOverrides = [],
        array $clusterOverrides = [],
        array $baselineNodeOverrides = [],
        array $baselineClusterOverrides = [],
    ): array {
        [$routes, $proposals] = $this->proposals(
            $nodeOverrides,
            $clusterOverrides,
            $baselineNodeOverrides,
            $baselineClusterOverrides,
        );
        $changes = [];

        foreach ($routes as $route) {
            $proposal = $proposals[$route->id];

            if (
                $route->status !== RouteStatus::Active
                || $route->publication !== RoutePublication::Private
            ) {
                continue;
            }

            if (
                $route->domain === $proposal['domain']
                && $route->node_id === $proposal['node_id']
                && $route->cluster_id === $proposal['cluster_id']
            ) {
                continue;
            }

            $changes[] = [
                'route' => $route,
                'domain' => $proposal['domain'],
                'placement' => new RoutePlacement(
                    nodeId: $proposal['node_id'],
                    clusterId: $proposal['cluster_id'],
                    effectiveTld: null,
                ),
            ];
        }

        return $changes;
    }

    /**
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $nodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $clusterOverrides
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $baselineNodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $baselineClusterOverrides
     * @return array{Collection<int, Route>, array<int, array{node_id: ?int, cluster_id: ?int, generation_basis_node_id: ?int, domain: string}>}
     */
    private function proposals(
        array $nodeOverrides,
        array $clusterOverrides,
        array $baselineNodeOverrides,
        array $baselineClusterOverrides,
    ): array {
        $domains = DB::table('routes')
            ->lockForUpdate()
            ->orderBy('id')
            ->pluck('id', 'domain')
            ->all();
        $routes = $this->affectedRoutes(
            $nodeOverrides,
            $clusterOverrides,
            $baselineNodeOverrides,
            $baselineClusterOverrides,
        );
        $proposals = [];

        foreach ($routes as $route) {
            unset($domains[$route->domain]);
        }

        foreach ($routes as $route) {
            $proposal = $this->proposal(
                $route,
                $nodeOverrides,
                $clusterOverrides,
                $baselineNodeOverrides,
                $baselineClusterOverrides,
            );
            $owner = $domains[$proposal['domain']] ?? null;

            if ($owner !== null && $owner !== $route->id) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_conflict',
                    message: "Route domain [{$proposal['domain']}] would collide.",
                    status: 409,
                );
            }

            $domains[$proposal['domain']] = $route->id;
            $proposals[$route->id] = $proposal;
        }

        return [$routes, $proposals];
    }

    /**
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $nodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $clusterOverrides
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $baselineNodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $baselineClusterOverrides
     * @return Collection<int, Route>
     */
    private function affectedRoutes(
        array $nodeOverrides,
        array $clusterOverrides,
        array $baselineNodeOverrides,
        array $baselineClusterOverrides,
    ): Collection {
        $nodeIds = $this->affectedIds($nodeOverrides, $baselineNodeOverrides);
        $clusterIds = $this->affectedIds($clusterOverrides, $baselineClusterOverrides);

        return Route::query()
            ->with(['app', 'targets.appInstance.node', 'generationBasisNode'])
            ->where(function (Builder $query) use ($nodeIds, $clusterIds): void {
                $query->whereRaw('0 = 1');

                if ($nodeIds !== []) {
                    $query
                        ->orWhereIn('node_id', $nodeIds)
                        ->orWhereIn('generation_basis_node_id', $nodeIds)
                        ->orWhereHas(
                            'targets.appInstance',
                            static fn (Builder $target): Builder => $target->whereIn('node_id', $nodeIds),
                        );
                }

                if ($clusterIds !== []) {
                    $query
                        ->orWhereIn('cluster_id', $clusterIds)
                        ->orWhereHas(
                            'node',
                            static fn (Builder $node): Builder => $node->whereIn('cluster_id', $clusterIds),
                        )
                        ->orWhereHas(
                            'generationBasisNode',
                            static fn (Builder $node): Builder => $node->whereIn('cluster_id', $clusterIds),
                        )
                        ->orWhereHas(
                            'targets.appInstance.node',
                            static fn (Builder $node): Builder => $node->whereIn('cluster_id', $clusterIds),
                        );
                }
            })
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, mixed>  $overrides
     * @param  array<int, mixed>  $baselineOverrides
     * @return list<int>
     */
    private function affectedIds(array $overrides, array $baselineOverrides): array
    {
        return array_values(array_unique([
            ...array_map(intval(...), array_keys($overrides)),
            ...array_map(intval(...), array_keys($baselineOverrides)),
        ]));
    }

    /**
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $nodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $clusterOverrides
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $baselineNodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $baselineClusterOverrides
     * @return array{node_id: ?int, cluster_id: ?int, generation_basis_node_id: ?int, domain: string}
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
                    message: "Route [{$route->domain}] targets would span routing scopes.",
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

        $domain = $route->domain;

        if ($route->provenance === RouteProvenance::Generated) {
            if ($firstTarget instanceof AppInstance && ! $firstTarget->migration_required) {
                $domain = $this->state->generatedDomain(
                    $route->app->slug,
                    $firstTarget->name,
                    $placement->effectiveTld,
                );
            } elseif (! $firstTarget instanceof AppInstance) {
                $domain = $this->rebaseRetainedDomain(
                    $route,
                    $placement,
                    $baselineNodeOverrides,
                    $baselineClusterOverrides,
                );
            }
        }

        return [
            'node_id' => $placement->nodeId,
            'cluster_id' => $placement->clusterId,
            'generation_basis_node_id' => $route->generation_basis_node_id,
            'domain' => RouteDomain::validate($domain),
        ];
    }

    /**
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $nodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $clusterOverrides
     */
    private function placementWithoutTarget(Route $route, array $nodeOverrides, array $clusterOverrides): RoutePlacement
    {
        if ($route->provenance === RouteProvenance::Generated) {
            $basis = $route->generationBasisNode;

            if (! $basis instanceof Node) {
                throw new ResourceOperationException(
                    errorCode: 'route.generation_basis_missing',
                    message: "Generated Route [{$route->domain}] has no generation basis.",
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
                message: "Targetless Route [{$route->domain}] cannot leave its Cluster scope.",
                status: 409,
            );
        }

        return new RoutePlacement(nodeId: null, clusterId: $cluster->id, effectiveTld: null);
    }

    /** @param array{node_id: ?int, cluster_id: ?int, generation_basis_node_id: ?int, domain: string} $proposal */
    private function replacePendingGenerated(Route $route, array $proposal): void
    {
        $replacement = Route::query()->create([
            'app_id' => $route->app_id,
            'node_id' => $proposal['node_id'],
            'cluster_id' => $proposal['cluster_id'],
            'generation_basis_node_id' => $proposal['generation_basis_node_id'],
            'domain' => $proposal['domain'],
            'provenance' => $route->provenance,
            'publication' => $route->publication,
            'status' => RouteStatus::Pending,
            'replaces_route_id' => $route->id,
            'replacement_step' => RouteReplacementStep::Reserved,
        ]);

        foreach ($route->targets as $target) {
            $replacement->targets()->create([
                'app_instance_id' => $target->app_instance_id,
                'position' => $target->position,
            ]);
        }

        $route->targets()->delete();
        $route->delete();
        $replacement->update([
            'replaces_route_id' => null,
            'replacement_step' => null,
        ]);
    }

    private function assertTarget(Route $route, AppInstance $target): void
    {
        if ($target->app_id !== $route->app_id || $target->status !== AppInstanceState::Active) {
            throw new ResourceOperationException(
                errorCode: 'route.target_invalid',
                message: "Route [{$route->domain}] has an invalid target.",
                status: 409,
            );
        }
    }

    /**
     * @param  array<int, array{tld?: ?string, cluster_id?: ?int}>  $baselineNodeOverrides
     * @param  array<int, array{tld?: ?string, state?: ClusterState}>  $baselineClusterOverrides
     */
    private function rebaseRetainedDomain(
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
                message: "Generated Route [{$route->domain}] would have no effective TLD.",
                status: 409,
            );
        }

        if ($currentTld === $proposedTld) {
            return $route->domain;
        }

        if ($currentTld === null || ! str_ends_with($route->domain, ".{$currentTld}")) {
            throw new ResourceOperationException(
                errorCode: 'route.generation_basis_invalid',
                message: "Generated Route [{$route->domain}] cannot be reconciled from its stored basis.",
                status: 409,
            );
        }

        return substr($route->domain, 0, -strlen($currentTld)).$proposedTld;
    }
}
