<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Domain\Nodes\RoleName;
use App\Models\Node;

/**
 * The listener addresses and admitted clients of one Node's sites (ADR 0141, amended by ADR 0157). The Node
 * Caddy build and Doctor choose each `bind` here, so they cannot disagree about a Node's listeners.
 */
final readonly class NodeCaddyListeners
{
    public const string Wildcard = '0.0.0.0';

    /** The client ranges beside the VPN subnet that a private site on an Ingress Node admits: private and shared address space. */
    public const string PrivateClients = 'private_ranges 100.64.0.0/10';

    /**
     * @param  list<string>  $explicit  The WireGuard address, then the LAN address when the Node has one.
     */
    private function __construct(
        public bool $ingress,
        public ?string $wireGuard,
        public array $explicit,
        private string $vpnSubnet,
    ) {}

    /** @param list<CaddySite> $sites Every site on the Node. The listeners do not depend on them. */
    public static function forSites(Node $node, array $sites = [], string $vpnSubnet = '10.44.0.0/24'): self
    {
        $wireGuard = self::address($node->wireguard_ip);

        return new self(
            ingress: $node->exists && CaddySiteRoles::nodeServes($node->id, RoleName::Ingress),
            wireGuard: $wireGuard,
            explicit: array_values(array_unique(array_filter([$wireGuard, self::address($node->lan_ip)]))),
            vpnSubnet: $vpnSubnet,
        );
    }

    /**
     * Only public Ingress sites bind every address. They also bind the WireGuard and LAN addresses, because a
     * connection to a specific address reaches only the sites bound to it: a Router forwards to those addresses,
     * and public traffic can arrive on the LAN address behind NAT. Every other site binds only the addresses its
     * clients use, so no private site joins the public listener. An empty list means the Node has no WireGuard
     * address.
     *
     * @return list<string>
     */
    public function bind(CaddyListenerRule $rule, int $port): array
    {
        return match ($rule) {
            CaddyListenerRule::Public => [self::Wildcard, ...$this->explicit],
            CaddyListenerRule::Wildcard => $this->wireGuard === null ? [] : $this->explicit,
            CaddyListenerRule::WireGuard, CaddyListenerRule::Shared => $this->wireGuard === null ? [] : [$this->wireGuard],
        };
    }

    /**
     * The client ranges a site admits, or null when it admits every client. WireGuard-only sites admit the VPN
     * subnet, so a LAN neighbour that routes to the WireGuard address gets no answer. Private sites on an Ingress
     * Node, whose firewall admits public HTTP and HTTPS, admit private address space and the VPN subnet, so a public
     * client that reaches the LAN address, for example through a port forward, gets no answer.
     */
    public function clients(CaddyListenerRule $rule): ?string
    {
        return match ($rule) {
            CaddyListenerRule::Public => null,
            CaddyListenerRule::Wildcard => $this->ingress ? self::PrivateClients.' '.$this->vpnSubnet : null,
            CaddyListenerRule::WireGuard, CaddyListenerRule::Shared => $this->vpnSubnet,
        };
    }

    private static function address(?string $address): ?string
    {
        return is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $address : null;
    }
}
