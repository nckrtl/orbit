<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\Fleet\FleetNodeConverger;
use App\Domain\Fleet\FootprintArtifactSkipped;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildResult;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Node;

/** The Node's Caddyfile, published like `orbit:caddy-build`: a graceful reload only when it changed. */
final readonly class CaddyFootprintArtifact implements NodeFootprintArtifact
{
    public const string RenderRefused = 'caddy_render_refused';

    public const string ValidateRefused = 'caddy_validate_refused';

    public function __construct(
        private NodeCaddyfileRenderer $renderer,
        private NodeCaddyBuilds $builds,
    ) {}

    public function name(): string
    {
        return 'caddy';
    }

    public function applies(Node $node): bool
    {
        return $node->platform === 'linux'
            && (CaddySiteRoles::nodeExpectsCaddy($node->id) || $this->renderer->render($node)->sites !== []);
    }

    /**
     * The version of the Caddy build code, not the rendered Caddyfile: a user's site or Route never makes a
     * Node drift. Site changes publish themselves, and Doctor's `role.caddy_build_drift` reports live drift.
     */
    public function digest(Node $node): string
    {
        return SourceDigest::of(self::inputs());
    }

    /**
     * Every Gateway source file the Caddy build renders from: the build, its site sources, and the classes
     * they use, such as `DevelopmentSite` and the `CaddyRelease` pin.
     *
     * @return list<string>
     */
    public static function inputs(): array
    {
        return SourceClosure::of(['app/Infrastructure/Caddy']);
    }

    public function apply(Node $node): bool
    {
        try {
            return $this->builds->build($node) === NodeCaddyBuildResult::Published;
        } catch (NodeCaddyBuildException $exception) {
            // A render problem names a site that cannot be built, which is a user's data: skip, keep the live
            // Caddyfile, and let Doctor report the drift. A validation failure can also be Orbit's own template
            // or Caddy pin, so the rollout fails it on the first Node it visits ({@see FleetNodeConverger}).
            if ($exception->stage === 'render') {
                throw new FootprintArtifactSkipped(self::RenderRefused, 'The Caddyfile was not published: '.$exception->getMessage());
            }

            if ($exception->stage === 'validate') {
                throw new FootprintArtifactSkipped(self::ValidateRefused, 'Caddy refused the Caddyfile: '.$exception->getMessage());
            }

            throw new ResourceOperationException(
                'node.footprint_caddy_failed',
                'The Caddyfile could not be published: '.$exception->getMessage(),
                502,
                $exception,
                ['stage' => $exception->stage],
            );
        }
    }
}
