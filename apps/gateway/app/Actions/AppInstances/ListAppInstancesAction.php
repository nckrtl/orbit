<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListAppInstancesAction
{
    public function __construct(
        private NodeAccessAuthorizer $access,
    ) {}

    /** @return Collection<int, AppInstance> */
    public function handle(Node $consumer): Collection
    {
        return AppInstance::query()
            ->when(
                ! $this->access->hasGatewayAuthority($consumer),
                fn ($query) => $query->whereIn('node_id', $this->access->accessibleNodeIds($consumer)),
            )
            ->latest('id')
            ->get();
    }
}
