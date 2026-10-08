<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Fleet\FootprintArtifactSkipped;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Fleet\Footprint\CaddyFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\PrivateDnsFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\SourceDigest;
use App\Models\Node;
use Tests\Support\FakeNodeCaddyBuilds;
use Tests\Support\Fleet\FleetFixtures;

describe('footprint digests', function (): void {
    it('pins the Caddy digest to the code the Caddy build renders from, never to a rendered Caddyfile', function (): void {
        $inputs = CaddyFootprintArtifact::inputs();
        $artifact = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), new FakeNodeCaddyBuilds);
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);

        expect($inputs)->toContain(
            'app/Infrastructure/Caddy/Build/NodeCaddyfileRenderer.php',
            'app/Infrastructure/Caddy/Build/Sources/RouteCaddySiteSource.php',
            'app/Infrastructure/AppDev/DevelopmentSite.php',
            'app/Domain/Nodes/CaddyRelease.php',
        )
            ->and(array_filter($inputs, static fn (string $path): bool => str_starts_with($path, 'app/Models/')))->toBe([])
            ->and($artifact->digest($node))->toBe(SourceDigest::of($inputs))
            ->and($artifact->digest($node))->not->toBe(app(NodeCaddyfileRenderer::class)->render($node)->version);
    });

    it('keeps the private-DNS digest when a Node joins and the records change', function (): void {
        $vpn = FleetFixtures::node('vpn', [RoleName::Vpn]);
        $artifact = new PrivateDnsFootprintArtifact(new class implements PrivateDnsManager
        {
            public function converge(?Node $pendingNode = null): void {}
        });
        $before = $artifact->digest($vpn);

        FleetFixtures::node('joined', [RoleName::AppDev]);

        expect($artifact->digest($vpn))->toBe($before);
    });

    it('keeps the Caddy digest the same for every Node, whatever its sites', function (): void {
        $artifact = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), new FakeNodeCaddyBuilds);

        expect($artifact->digest(FleetFixtures::node('dev', [RoleName::AppDev])))
            ->toBe($artifact->digest(FleetFixtures::node('prod', [RoleName::AppProd])));
    });

    it('skips a Caddyfile the render or validation refuses with its reason, and fails a broken publish', function (): void {
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $builds = new FakeNodeCaddyBuilds;
        $artifact = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), $builds);

        foreach (['render' => 'caddy_render_refused', 'validate' => 'caddy_validate_refused'] as $stage => $reason) {
            $builds->failures['dev'] = new NodeCaddyBuildException('dev', $stage, 'A site is invalid.');
            expect(fn () => $artifact->apply($node))->toThrow(fn (FootprintArtifactSkipped $skipped) => expect($skipped->reason)->toBe($reason));
        }

        $builds->failures['dev'] = new NodeCaddyBuildException('dev', 'reload', 'Caddy did not reload.');
        expect(fn () => $artifact->apply($node))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('node.footprint_caddy_failed'));
    });
});
