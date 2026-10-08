<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Domain\AppDev\AppDevPhpFpmManager;
use App\Domain\Routes\RouteRemovalStep;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
use App\Infrastructure\AppDev\WithdrawnSitePhpConvergence;
use App\Models\Node;
use App\Models\RouteRemovalResidue;

/**
 * Finishes an offline Route removal on a Node that answers again. The Route record is gone, so the
 * Caddy build and the PHP-FPM convergence render the Node without its sites, and the certificates and
 * firewall rules go by the Route ID. Each step repeats safely, so a failure keeps the rows for a retry.
 */
final readonly class RouteRemovalResidueCleaner
{
    public function __construct(
        private RemoteAppDevCaddyManager $caddy,
        private AppDevPhpFpmManager $php,
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevRouteFirewallManager $firewall,
    ) {}

    /** Returns whether the Node had residue to remove. */
    public function clean(Node $node): bool
    {
        $residues = RouteRemovalResidue::query()->where('node_id', $node->id)->orderBy('route_id')->get();

        if ($residues->isEmpty()) {
            return false;
        }

        $needs = static fn (RouteRemovalStep $step): bool => $residues->contains(
            static fn (RouteRemovalResidue $residue): bool => in_array($step, $residue->removalSteps(), true),
        );

        if ($needs(RouteRemovalStep::Caddy)) {
            $this->caddy->build($node);
        }

        if ($needs(RouteRemovalStep::Php)) {
            new WithdrawnSitePhpConvergence($this->php)->converge($node);
        }

        foreach ($residues as $residue) {
            if (in_array(RouteRemovalStep::Certificates, $residue->removalSteps(), true)) {
                $this->certificates->removeRemovedRoute($residue->route_id, $node);
            }

            if (in_array(RouteRemovalStep::Firewall, $residue->removalSteps(), true)) {
                $this->firewall->remove($node, $residue->route_id);
            }
        }

        RouteRemovalResidue::query()->whereKey($residues->modelKeys())->delete();

        return true;
    }
}
