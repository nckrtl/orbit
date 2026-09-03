<?php

declare(strict_types=1);

namespace App\Domain\Clusters;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Node;

final readonly class ActiveTldScopeGuard
{
    public function assertClusterTldAvailable(Cluster $cluster, ?string $tld, ClusterState $state): void
    {
        if ($state !== ClusterState::Active || $tld === null) {
            return;
        }

        $matchingNode = Node::query()->where('tld', $tld)->first();

        if (! $matchingNode instanceof Node || $matchingNode->cluster_id === $cluster->id) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'cluster.tld_conflict',
            message: "Cluster TLD [{$tld}] belongs to non-member Node [{$matchingNode->name}].",
            status: 409,
        );
    }

    public function assertNodeTldAvailable(Node $node, ?string $tld, ?int $clusterId): void
    {
        if ($tld === null) {
            return;
        }

        $matchingCluster = Cluster::query()
            ->where('state', ClusterState::Active)
            ->where('tld', $tld)
            ->first();

        if (! $matchingCluster instanceof Cluster || $matchingCluster->id === $clusterId) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'node.tld_conflict',
            message: "Node [{$node->name}] TLD [{$tld}] belongs to active Cluster [{$matchingCluster->name}].",
            status: 409,
        );
    }

    public function assertNodeCanDetach(Cluster $cluster, Node $node): void
    {
        if (
            $cluster->state !== ClusterState::Active
            || $cluster->tld === null
            || $cluster->tld !== $node->tld
        ) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'cluster.tld_conflict',
            message: "Node [{$node->name}] cannot leave while it shares active Cluster TLD [{$cluster->tld}].",
            status: 409,
        );
    }
}
