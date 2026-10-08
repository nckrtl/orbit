<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\Fleet\NodeFootprintArtifact;
use App\Infrastructure\Routes\RouteRemovalResidueCleaner;
use App\Models\Node;
use App\Models\RouteRemovalResidue;

/**
 * What an offline Route removal left on the Node. Unlike the other artifacts, its digest comes from
 * records: the residue is Orbit's own unfinished removal, so the Node drifts until the next converge
 * removes it. Once the rows are gone the artifact no longer applies.
 */
final readonly class RouteResidueFootprintArtifact implements NodeFootprintArtifact
{
    public function __construct(
        private RouteRemovalResidueCleaner $cleaner,
    ) {}

    public function name(): string
    {
        return 'route-residue';
    }

    public function applies(Node $node): bool
    {
        return RouteRemovalResidue::query()->where('node_id', $node->id)->exists();
    }

    public function digest(Node $node): string
    {
        $residues = RouteRemovalResidue::query()
            ->where('node_id', $node->id)
            ->orderBy('route_id')
            ->get()
            ->map(static fn (RouteRemovalResidue $residue): array => [$residue->route_id, $residue->steps])
            ->all();

        return hash('sha256', json_encode($residues, JSON_THROW_ON_ERROR));
    }

    public function apply(Node $node): bool
    {
        return $this->cleaner->clean($node);
    }
}
