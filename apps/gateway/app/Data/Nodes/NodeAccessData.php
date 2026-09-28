<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use App\Models\Node;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class NodeAccessData extends Data
{
    /**
     * @param  list<NodeAccessNodeData>  $canAccess
     * @param  list<NodeAccessNodeData>  $accessibleBy
     */
    public function __construct(
        public array $canAccess,
        public array $accessibleBy,
    ) {}

    public static function fromModel(Node $node): self
    {
        $canAccess = $node
            ->accessibleNodes
            ->map(NodeAccessNodeData::fromModel(...))
            ->values()
            ->all();
        $accessibleBy = $node
            ->accessingNodes
            ->map(NodeAccessNodeData::fromModel(...))
            ->values()
            ->all();

        return new self(
            canAccess: array_values($canAccess),
            accessibleBy: array_values($accessibleBy),
        );
    }
}
