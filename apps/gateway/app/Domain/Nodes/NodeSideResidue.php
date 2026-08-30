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
 */
final readonly class NodeSideResidue
{
    private const string EXPORTER = 'Metrics node exporter package, its Orbit systemd drop-in and its firewall rule for port 9100';

    public const string FOLLOW_UP = 'Run the node-local Metrics cleanup on the node once it boots, or discard the node.';

    /**
     * @param list<RoleName> $roles
     * @return list<string>
     */
    public function describe(array $roles): array
    {
        $lines = [self::EXPORTER];

        foreach ($roles as $role) {
            foreach ($this->forRole($role) as $line) {
                $lines[] = $line;
            }
        }

        $lines = array_values(array_unique($lines));
        sort($lines, SORT_STRING);

        return $lines;
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
            // Both are protected from removal, so neither can reach this path.
            RoleName::Gateway, RoleName::Vpn => [],
        };
    }
}
