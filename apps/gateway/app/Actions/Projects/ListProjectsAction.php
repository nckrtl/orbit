<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListProjectsAction
{
    public function __construct(
        private NodeAccessAuthorizer $access,
    ) {}

    /** @return Collection<int, Project> */
    public function handle(Node $consumer): Collection
    {
        return Project::query()
            ->when(
                ! $this->access->hasGatewayAuthority($consumer),
                fn ($query) => $query->whereHas(
                    'instances',
                    fn ($instances) => $instances->whereIn(
                        'node_id',
                        $this->access->accessibleNodeIds($consumer),
                    ),
                ),
            )
            // Alphabetical by name so a list reads as a directory and a picker stays predictable.
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
}
