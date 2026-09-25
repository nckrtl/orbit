<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;

/**
 * Routes the private VPN domain on the Gateway machine to Orbit VPN DNS, so the Orbit CLI and other
 * clients on that machine resolve `reverb.orbit`, `gateway.orbit`, and the other private names.
 * ADR 0156 records the decision.
 *
 * The route is suffix-only: systemd-resolved sends `~<domain>` queries over the `orbit` link to the
 * VPN DNS address and keeps every other query on the uplink resolvers, so a VPN DNS outage never
 * takes ordinary resolution away from the control plane. A drop-in on `wg-quick@orbit` reapplies
 * the route whenever the tunnel starts; its `-` prefix keeps a resolver failure from failing the
 * tunnel. A machine whose tunnel is a managed peer (`/etc/wireguard/orbit.dns-link` exists) keeps
 * the resolver policy that peer convergence owns, and this step leaves it alone.
 *
 * The routing domain and `default-route false` are set before the DNS server, so the link never
 * becomes a default DNS route, not even for a moment or after a later command fails.
 */
final readonly class GatewayPrivateDnsResolver
{
    public const string DROP_IN = '/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf';

    public const string PEER_DNS_STATE = '/etc/wireguard/orbit.dns-link';

    /**
     * The unit drop-in for one VPN DNS address and private domain.
     */
    public function dropIn(string $address, string $domain): string
    {
        [$address, $domain] = $this->validated($address, $domain);
        $peerState = self::PEER_DNS_STATE;

        return implode("\n", [
            '# Managed by Orbit.',
            '[Service]',
            "ExecStartPost=-/bin/sh -c 'test -e {$peerState} || { resolvectl domain orbit \"~{$domain}\" && resolvectl default-route orbit false && resolvectl dns orbit {$address}; }'",
            '',
        ]);
    }

    public function convergeCommand(string $address, string $domain): RemoteCommand
    {
        return new RemoteCommand(['sudo', 'bash', '-seu', '--', self::DROP_IN], $this->convergeScript($address, $domain));
    }

    public function removeCommand(): RemoteCommand
    {
        return new RemoteCommand(['sudo', 'bash', '-seu', '--', self::DROP_IN], $this->removeScript());
    }

    public function convergeScript(string $address, string $domain): string
    {
        [$address, $domain] = $this->validated($address, $domain);
        $encoded = base64_encode($this->dropIn($address, $domain));
        $peerState = self::PEER_DNS_STATE;

        return <<<BASH
            managed=\$1
            directory=\$(dirname -- "\$managed")
            candidate=\$directory/.orbit-gateway-dns.conf.\$\$.candidate
            staged=\$(mktemp)
            trap 'rm -f -- "\$staged" "\$candidate"' EXIT
            exec 9>/run/lock/orbit-wireguard-peer.lock
            flock -w 30 9
            printf '%s' '{$encoded}' | base64 --decode > "\$staged"
            if ! { [ -f "\$managed" ] && cmp -s -- "\$staged" "\$managed"; }; then
                install -d -o root -g root -m 0755 -- "\$directory"
                install -o root -g root -m 0644 -- "\$staged" "\$candidate"
                mv -fT -- "\$candidate" "\$managed"
                systemctl daemon-reload
            fi
            if [ -e {$peerState} ] || ! ip link show dev orbit >/dev/null 2>&1; then
                exit 0
            fi
            resolvectl domain orbit '~{$domain}'
            resolvectl default-route orbit false
            resolvectl dns orbit {$address}
            BASH;
    }

    /**
     * The Orbit VPN DNS address: the configured VPN DNS server, or the WireGuard address of the Node
     * that holds an active `vpn` role. Null while no Node serves VPN DNS.
     */
    public function vpnDnsAddress(VpnSettings $settings): ?string
    {
        $configured = $settings->dnsServer();

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $address = Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereHas(
                'roles',
                static fn ($query) => $query
                    ->where('role', RoleName::Vpn->value)
                    ->where('status', LifecycleStatus::Active),
            )
            ->value('wireguard_ip');

        return is_string($address) && $address !== '' ? $address : null;
    }

    /**
     * A read-only probe that prints `1` when the drop-in matches and the live `orbit` link routes only
     * `~<domain>` to the address without being a default DNS route, or when the machine is a managed
     * peer whose policy peer convergence owns. It prints `0` otherwise.
     */
    public function inspectCommand(string $address, string $domain): RemoteCommand
    {
        [$address, $domain] = $this->validated($address, $domain);
        $peerState = self::PEER_DNS_STATE;

        return new RemoteCommand(
            ['sudo', 'bash', '-seu', '--', self::DROP_IN, base64_encode($this->dropIn($address, $domain)), $address, "~{$domain}"],
            <<<BASH
                managed=\$1
                expected=\$2
                address=\$3
                domain=\$4
                if [ -e {$peerState} ]; then
                    printf '1\n'
                    exit 0
                fi
                link_value() {
                    output=\$(resolvectl "\$1" orbit 2>/dev/null) || return 1
                    printf '%s' "\${output#*: }"
                }
                if [ -f "\$managed" ] \
                    && printf '%s' "\$expected" | base64 --decode | cmp -s -- - "\$managed" \
                    && [ "\$(link_value domain)" = "\$domain" ] \
                    && [ "\$(link_value default-route)" = no ] \
                    && [ "\$(link_value dns)" = "\$address" ]; then
                    printf '1\n'
                else
                    printf '0\n'
                fi
                BASH,
        );
    }

    public function removeScript(): string
    {
        $peerState = self::PEER_DNS_STATE;

        return <<<BASH
            managed=\$1
            exec 9>/run/lock/orbit-wireguard-peer.lock
            flock -w 30 9
            if [ ! -e "\$managed" ]; then
                exit 0
            fi
            rm -f -- "\$managed"
            rmdir --ignore-fail-on-non-empty -- "\$(dirname -- "\$managed")"
            systemctl daemon-reload
            if [ -e {$peerState} ] || ! ip link show dev orbit >/dev/null 2>&1; then
                exit 0
            fi
            resolvectl revert orbit
            BASH;
    }

    /** @return array{string, string} */
    private function validated(string $address, string $domain): array
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new NodeProvisioningException(
                step: 'gateway-private-dns-resolver',
                errorCode: 'vpn.configuration_invalid',
                message: "The VPN DNS address [{$address}] is not an IP address.",
            );
        }

        if (
            filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || preg_match('/^[A-Za-z0-9.-]+$/', $domain) !== 1
        ) {
            throw new NodeProvisioningException(
                step: 'gateway-private-dns-resolver',
                errorCode: 'vpn.configuration_invalid',
                message: "The private DNS domain [{$domain}] is invalid.",
            );
        }

        return [$address, $domain];
    }
}
