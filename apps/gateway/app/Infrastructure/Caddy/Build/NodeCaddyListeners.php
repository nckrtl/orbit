<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Domain\Nodes\RoleName;
use App\Models\Node;

/**
 * The listener addresses of one Node's sites under the ADR 0141 rules. The Node Caddy build and Doctor
 * choose each `bind` here, so they cannot disagree about a Node's listeners.
 */
final readonly class NodeCaddyListeners
{
    public const string Wildcard = '0.0.0.0';

    /**
     * @param  list<string>  $explicit  The WireGuard address, then the LAN address when the Node has one.
     * @param  array<int, CaddySite>  $wildcardSites  The first first-row site on each port that binds every address.
     * @param  array<int, true>  $wireGuardOnlyPorts  The ports that carry a WireGuard-only site.
     */
    private function __construct(
        public bool $ingress,
        public ?string $wireGuard,
        public array $explicit,
        private array $wildcardSites,
        private array $wireGuardOnlyPorts,
    ) {}

    /** @param list<CaddySite> $sites Every site on the Node, or at least every first-row site. */
    public static function forSites(Node $node, array $sites): self
    {
        $wireGuard = self::address($node->wireguard_ip);
        $ingress = $node->exists && CaddySiteRoles::nodeServes($node->id, RoleName::Ingress);
        $wildcardSites = [];
        $wireGuardOnlyPorts = [];

        foreach ($sites as $site) {
            if ($ingress && $site->listener === CaddyListenerRule::Wildcard && ! array_key_exists($site->port, $wildcardSites)) {
                $wildcardSites[$site->port] = $site;
            }

            if ($site->listener === CaddyListenerRule::WireGuard) {
                $wireGuardOnlyPorts[$site->port] = true;
            }
        }

        return new self(
            ingress: $ingress,
            wireGuard: $wireGuard,
            explicit: array_values(array_unique(array_filter([$wireGuard, self::address($node->lan_ip)]))),
            wildcardSites: $wildcardSites,
            wireGuardOnlyPorts: $wireGuardOnlyPorts,
        );
    }

    /**
     * First-row sites bind every address on an Ingress Node, where public sites already do. Elsewhere
     * they bind the Node's WireGuard and LAN addresses, the only ones Routers, workloads, and clients
     * use, so no wildcard listener exists and WireGuard-only sites can share their port. A shared site
     * joins the wildcard listener only beside a first-row site on its port. An empty list means the
     * Node has no WireGuard address.
     *
     * A WireGuard-only site such as `gateway.orbit` opens a listener on the WireGuard address, and Caddy
     * sends every connection to that address to that listener alone. So on a port that carries one, a
     * site that binds `0.0.0.0` also binds the WireGuard address, and WireGuard clients still reach it.
     * The WireGuard-only site never joins the wildcard listener.
     *
     * @return list<string>
     */
    public function bind(CaddyListenerRule $rule, int $port): array
    {
        $bind = match ($rule) {
            CaddyListenerRule::Public => [self::Wildcard],
            CaddyListenerRule::Wildcard => $this->ingress ? [self::Wildcard] : ($this->wireGuard === null ? [] : $this->explicit),
            CaddyListenerRule::WireGuard => $this->wireGuard === null ? [] : [$this->wireGuard],
            CaddyListenerRule::Shared => array_key_exists($port, $this->wildcardSites)
                ? [self::Wildcard]
                : ($this->wireGuard === null ? [] : [$this->wireGuard]),
        };

        if ($bind === [self::Wildcard] && $this->wireGuard !== null && array_key_exists($port, $this->wireGuardOnlyPorts)) {
            return [self::Wildcard, $this->wireGuard];
        }

        return $bind;
    }

    private static function address(?string $address): ?string
    {
        return is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $address : null;
    }
}
