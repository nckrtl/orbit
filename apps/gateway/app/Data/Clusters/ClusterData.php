<?php

declare(strict_types=1);

namespace App\Data\Clusters;

use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class ClusterData extends Data
{
    /**
     * @param list<ClusterNodeData> $nodes
     * @mago-expect lint:excessive-parameter-list The value mirrors the complete Cluster response.
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $tld,
        public string $state,
        public array $nodes,
        public ?ClusterNodeData $router,
    ) {}

    public static function fromModel(Cluster $cluster): self
    {
        $cluster->loadMissing([
            'nodes' => static function (HasMany $query): void {
                $query->orderBy('name');
                $query->orderBy('id');
            },
            'routerAssignment.node',
        ]);
        $router = $cluster->routerAssignment?->node;

        /** @var list<ClusterNodeData> $nodes */
        $nodes = $cluster
            ->nodes
            ->map(static fn (Node $node): ClusterNodeData => ClusterNodeData::fromModel($node))
            ->values()
            ->all();

        return new self(
            id: $cluster->id,
            name: $cluster->name,
            tld: $cluster->tld,
            state: $cluster->state->value,
            nodes: $nodes,
            router: $router instanceof Node ? ClusterNodeData::fromModel($router) : null,
        );
    }
}
