<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\Fleet\FootprintArtifactSkipped;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Infrastructure\Routes\RouteRemovalResidueCleaner;
use App\Models\Node;
use App\Models\RouteRemovalResidue;
use Throwable;

/**
 * What an offline Route removal left on the Node. Unlike the other artifacts, its digest comes from
 * records: the residue is Orbit's own unfinished removal, so the Node drifts until the next converge
 * removes it. Once the rows are gone the artifact no longer applies.
 */
final readonly class RouteResidueFootprintArtifact implements NodeFootprintArtifact
{
    public const string CleanupFailed = 'route_residue_cleanup_failed';

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
            ->map(static fn (RouteRemovalResidue $residue): array => [$residue->route_id, $residue->steps, $residue->attempts])
            ->all();

        return hash('sha256', json_encode($residues, JSON_THROW_ON_ERROR));
    }

    /**
     * A failure is skipped, not failed: another site's broken Caddyfile or PHP-FPM pool must not halt the
     * fleet rollout. The rows stay for Doctor, and the attempt count changes the digest, so the next
     * converge tries again.
     */
    public function apply(Node $node): bool
    {
        try {
            return $this->cleaner->clean($node);
        } catch (Throwable $exception) {
            RouteRemovalResidue::query()->where('node_id', $node->id)->increment('attempts');

            throw new FootprintArtifactSkipped(
                self::CleanupFailed,
                'The projections of removed Routes were not withdrawn: '.$exception->getMessage(),
            );
        }
    }
}
