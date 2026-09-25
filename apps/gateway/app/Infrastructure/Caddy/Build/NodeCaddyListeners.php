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
     */
    private function __construct(
        public bool $ingress,
        public ?string $wireGuard,
        public array $explicit,
        private array $wildcardSites,
    ) {}

    /** @param list<CaddySite> $sites Every site on the Node, or at least every first-row site. */
    public static function forSites(Node $node, array $sites): self
    {
        $wireGuard = self::address($node->wireguard_ip);
        $ingress = $node->exists && CaddySiteRoles::nodeServes($node->id, RoleName::Ingress);
        $wildcardSites = [];

        foreach ($sites as $site) {
            if ($ingress && $site->listener === CaddyListenerRule::Wildcard && ! array_key_exists($site->port, $wildcardSites)) {
                $wildcardSites[$site->port] = $site;
            }
        }

        return new self(
            ingress: $ingress,
            wireGuard: $wireGuard,
            explicit: array_values(array_unique(array_filter([$wireGuard, self::address($node->lan_ip)]))),
            wildcardSites: $wildcardSites,
        );
    }

    /**
     * First-row sites bind every address on an Ingress Node, where public sites already do. Elsewhere
     * they bind the Node's WireGuard and LAN addresses, the only ones Routers, workloads, and clients
     * use, so no wildcard listener exists and WireGuard-only sites can share their port. A shared site
     * joins the wildcard listener only beside a first-row site on its port. An empty list means the
     * Node has no WireGuard address.
     *
     * @return list<string>
     */
    public function bind(CaddyListenerRule $rule, int $port): array
    {
        return match ($rule) {
            CaddyListenerRule::Public => [self::Wildcard],
            CaddyListenerRule::Wildcard => $this->ingress ? [self::Wildcard] : ($this->wireGuard === null ? [] : $this->explicit),
            CaddyListenerRule::WireGuard => $this->wireGuard === null ? [] : [$this->wireGuard],
            CaddyListenerRule::Shared => array_key_exists($port, $this->wildcardSites)
                ? [self::Wildcard]
                : ($this->wireGuard === null ? [] : [$this->wireGuard]),
        };
    }

    /** The first-row site that puts `0.0.0.0` on this port, which only happens on an Ingress Node. */
    public function wildcardSite(int $port): ?CaddySite
    {
        return $this->wildcardSites[$port] ?? null;
    }

    private static function address(?string $address): ?string
    {
        return is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $address : null;
    }
}
