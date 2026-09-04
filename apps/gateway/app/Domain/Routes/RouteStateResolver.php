<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class RouteStateResolver
{
    /**
     * @param array<int, array{tld?: ?string, cluster_id?: ?int}> $nodeOverrides
     * @param array<int, array{tld?: ?string, state?: ClusterState}> $clusterOverrides
     */
    public function forNode(Node $node, array $nodeOverrides = [], array $clusterOverrides = []): RoutePlacement
    {
        $nodeOverride = $nodeOverrides[$node->id] ?? [];
        $nodeTld = array_key_exists('tld', $nodeOverride) ? $nodeOverride['tld'] : $node->tld;
        $clusterId = array_key_exists('cluster_id', $nodeOverride)
            ? $nodeOverride['cluster_id']
            : $node->cluster_id;
        $cluster = $clusterId === null ? null : Cluster::query()->findOrFail($clusterId);

        if ($cluster instanceof Cluster) {
            $clusterOverride = $clusterOverrides[$cluster->id] ?? [];
            $clusterState = $clusterOverride['state'] ?? $cluster->state;
            $clusterTld = array_key_exists('tld', $clusterOverride) ? $clusterOverride['tld'] : $cluster->tld;

            if ($clusterState === ClusterState::Active) {
                return new RoutePlacement(
                    nodeId: null,
                    clusterId: $cluster->id,
                    effectiveTld: $nodeTld ?? $clusterTld,
                );
            }
        }

        return new RoutePlacement(
            nodeId: $node->id,
            clusterId: null,
            effectiveTld: $nodeTld,
        );
    }

    public function assertRouter(int $clusterId): void
    {
        $cluster = Cluster::query()->findOrFail($clusterId);
        $hasRouter = NodeRole::query()
            ->where('cluster_id', $clusterId)
            ->where('role', RoleName::Router)
            ->where('status', LifecycleStatus::Active)
            ->whereHas('node', static fn ($query) => $query->where('status', LifecycleStatus::Active))
            ->exists();

        if (! $hasRouter) {
            throw new ResourceOperationException(
                errorCode: 'route.router_required',
                message: "Cluster [{$cluster->name}] requires one active Router for its Routes.",
                status: 409,
            );
        }
    }

    public function generatedHostname(string $appSlug, string $mainBranch, string $instanceName, ?string $tld): string
    {
        if ($tld === null) {
            throw new ResourceOperationException(
                errorCode: 'route.tld_required',
                message: 'A generated Route requires a Node TLD or active Cluster TLD.',
                status: 409,
            );
        }

        $prefix = $instanceName === $mainBranch ? $appSlug : "{$instanceName}.{$appSlug}";

        return RouteHostname::validate("{$prefix}.{$tld}");
    }
}
