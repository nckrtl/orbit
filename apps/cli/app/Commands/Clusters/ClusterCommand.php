<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Commands\GatewayCommand;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Clusters\ShowClusterRequest;
use Orbit\Sdk\Responses\Clusters\ClusterNodeResponse;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

abstract class ClusterCommand extends GatewayCommand
{
    protected function clusterId(): ?int
    {
        return $this->positiveId('cluster', 'Cluster', 'cluster.id_invalid');
    }

    protected function nodeId(): ?int
    {
        return $this->positiveId('node', 'Node', 'node.id_invalid');
    }

    protected function existingCluster(GatewayConnector $connector, int $clusterId): ?ClusterResponse
    {
        $cluster = $this->sendWithProgress($connector, new ShowClusterRequest($clusterId), ClusterResponse::class, ['Inspect Cluster', 'Inspecting Cluster', 'Inspected Cluster']);

        return $cluster instanceof ClusterResponse ? $cluster : null;
    }

    protected function validTld(string $tld): bool
    {
        $normalized = mb_strtolower(trim($tld));

        if (
            $normalized !== ''
            && strlen($normalized) <= 63
            && preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $normalized) === 1
        ) {
            return true;
        }

        $this->renderGatewayFailure(
            'cluster.tld_invalid',
            'TLD must be one DNS label.',
        );

        return false;
    }

    protected function confirmed(string $operation, ClusterResponse $cluster, ?int $nodeId = null): bool
    {
        return $this->confirmAction(
            "Confirm Cluster {$operation} [{$cluster->name} (#{$cluster->id})]".($nodeId === null ? '?' : " for Node #{$nodeId}?"),
            "Cluster {$operation} cancelled.",
            option: 'force',
            requiredCode: 'cluster.confirmation_required',
            requiredMessage: "Use --force to confirm Cluster {$operation}.",
        );
    }

    protected function renderCluster(ClusterResponse $cluster, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($cluster->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage($message);
        $this->writeHumanMessage("Request ID: {$cluster->requestId}");

        return self::SUCCESS;
    }

    protected function nodeLabel(?ClusterNodeResponse $node): string
    {
        return $node instanceof ClusterNodeResponse ? "{$node->name} (#{$node->id})" : '—';
    }

    /** @param list<ClusterNodeResponse> $nodes */
    protected function nodeList(array $nodes): string
    {
        if ($nodes === []) {
            return '—';
        }

        return implode(', ', array_map(
            $this->nodeLabel(...),
            $nodes,
        ));
    }
}
