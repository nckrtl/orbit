<?php

declare(strict_types=1);

namespace App\Data\Routes;

use App\Models\RouteRemovalResidue;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** A Node an offline Route removal left unchanged, with the removal steps it still needs. */
#[MapOutputName(SnakeCaseMapper::class)]
final class RouteRemovalResidueData extends Data
{
    /** @param list<string> $steps */
    public function __construct(
        public int $nodeId,
        public string $node,
        public array $steps,
    ) {}

    public static function fromModel(RouteRemovalResidue $residue): self
    {
        $residue->loadMissing('node');

        return new self(
            nodeId: $residue->node_id,
            node: $residue->node->name,
            steps: $residue->steps,
        );
    }
}
