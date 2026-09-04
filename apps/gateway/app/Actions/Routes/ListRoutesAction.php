<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListRoutesAction
{
    public function __construct(
        private NodeAccessAuthorizer $access,
    ) {}

    /** @return Collection<int, Route> */
    public function handle(Node $consumer): Collection
    {
        $accessible = $this->access->accessibleNodeIds($consumer);

        return Route::query()
            ->with('targets')
            ->when(
                ! $this->access->hasGatewayAuthority($consumer),
                fn ($query) => $query->where(
                    fn ($routes) => $routes
                        ->whereIn('node_id', $accessible)
                        ->orWhereHas('cluster.nodes', fn ($nodes) => $nodes->whereIn('nodes.id', $accessible)),
                ),
            )
            ->latest('id')
            ->get();
    }
}
