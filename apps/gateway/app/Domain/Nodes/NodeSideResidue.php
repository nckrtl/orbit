<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

/**
 * What stays on a node when Orbit removes a role it could not reach.
 *
 * Gateway-side state is always cleaned. Everything a role baseline would have
 * torn down over SSH is not, and an operator is owed the list rather than a
 * silent success. The lines below mirror the node-side steps each baseline
 * skips, so a change to a baseline is a change here too.
 *
 * Whether the node itself is leaving matters. A node Orbit still owns keeps
 * being managed: its Metrics exporter is the fleet's business, and telling the
 * operator to wipe the node locally would strip state Orbit expects to find
 * when the node comes back. Only a node that has left the registry is fully
 * orphaned, and only then is the node-local escape the right answer.
 */
final readonly class NodeSideResidue
{
    private const string EXPORTER = 'Metrics node exporter package, its Orbit systemd drop-in and its firewall rule for port 9100';

    /** The node is gone from Orbit, so nothing on it is managed any more. */
    public const string FOLLOW_UP_NODE_REMOVED = 'Run the node-local Metrics cleanup on the node once it boots, or discard the node.';

    /** The node stays registered, so only the removed role's leftovers are stranded. */
    public const string FOLLOW_UP_ROLE_REMOVED = 'Orbit still manages this node. Clean up only the leftovers listed above; provision the node again when it is reachable.';

    /**
     * @param list<RoleName> $roles
     * @param bool $nodeLeavesFleet whether the node is leaving Orbit's registry too
     * @return list<string>
     *
     * @mago-expect lint:no-boolean-flag-parameter The residue differs in kind, not degree, when the node itself leaves.
     */
    public function describe(array $roles, bool $nodeLeavesFleet): array
    {
        // The exporter belongs to the fleet, not to any one role. It is only
        // stranded when the node stops being part of the fleet.
        $lines = $nodeLeavesFleet ? [self::EXPORTER] : [];

        foreach ($roles as $role) {
            foreach ($this->forRole($role) as $line) {
                $lines[] = $line;
            }
        }

        $lines = array_values(array_unique($lines));
        sort($lines, SORT_STRING);

        return $lines;
    }

    /** @mago-expect lint:no-boolean-flag-parameter The follow-up differs in kind when the node itself leaves. */
    public function followUp(bool $nodeLeavesFleet): string
    {
        return $nodeLeavesFleet ? self::FOLLOW_UP_NODE_REMOVED : self::FOLLOW_UP_ROLE_REMOVED;
    }

    /** @return list<string> */
    private function forRole(RoleName $role): array
    {
        return match ($role) {
            RoleName::AppDev => [
                'Caddy site configuration and certificates for the app-dev role',
                'Orbit firewall rules for the app-dev role',
                'Managed PHP-FPM pools, workspace checkouts and instance checkouts',
            ],
            RoleName::AppProd => [
                'Caddy site configuration and certificates for the app-prod role',
                'Orbit firewall rules for the app-prod role',
                'Managed PHP-FPM pools and instance checkouts',
            ],
            RoleName::Metrics => [
                'Prometheus and Grafana containers and their named volumes',
                '/etc/orbit/metrics including the Orbit ownership marker',
                'Grafana upstream firewall rule',
            ],
            // Gateway and VPN are protected from removal. Router and Ingress have no host projection.
            RoleName::Gateway, RoleName::Vpn, RoleName::Router, RoleName::Ingress => [],
        };
    }
}
