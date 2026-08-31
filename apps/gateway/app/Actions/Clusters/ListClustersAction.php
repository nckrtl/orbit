<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListClustersAction
{
    public function __construct(
        private NodeAccessAuthorizer $access,
    ) {}

    /** @return Collection<int, Cluster> */
    public function handle(Node $consumer): Collection
    {
        return Cluster::query()
            ->with(['nodes', 'routerAssignment.node'])
            ->when(
                ! $this->access->hasGatewayAuthority($consumer),
                fn ($query) => $query->whereHas(
                    'nodes',
                    fn ($nodes) => $nodes->whereIn('nodes.id', $this->access->accessibleNodeIds($consumer)),
                ),
            )
            ->latest('id')
            ->get();
    }
}
