<?php

declare(strict_types=1);

namespace App\Commands\Clusters;

use App\Commands\GatewayCommand;
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

    protected function confirmed(string $operation): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        if ($this->option('json') !== true && $this->input->isInteractive()) {
            return $this->confirm("Confirm Cluster {$operation}?", false);
        }

        $this->renderGatewayFailure(
            'cluster.confirmation_required',
            "Use --force to confirm Cluster {$operation}.",
        );

        return false;
    }

    protected function renderCluster(ClusterResponse $cluster, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($cluster->toArray());

            return self::SUCCESS;
        }

        $this->info($message);
        $this->line("Request ID: {$cluster->requestId}");

        return self::SUCCESS;
    }

    protected function nodeLabel(?ClusterNodeResponse $node): string
    {
        return $node instanceof ClusterNodeResponse ? "{$node->name} (#{$node->id})" : '-';
    }

    /** @param list<ClusterNodeResponse> $nodes */
    protected function nodeList(array $nodes): string
    {
        if ($nodes === []) {
            return '-';
        }

        return implode(', ', array_map(
            $this->nodeLabel(...),
            $nodes,
        ));
    }
}
