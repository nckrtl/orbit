<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsFootprint;
use App\Models\Node;

final class NodeFirewallRuleCatalog
{
    /** @return list<UfwManagedRule> */ public function forNode(Node $node): array
    {
        return [
            $this->rule('orbit:public-ssh-recovery', (string) $node->public_ssh_port),
            $this->wireguardMemberTrust($node),
        ];
    }

    /** @return list<UfwManagedRule> */ public function forRole(Node $node, RoleName $role): array
    {
        return match ($role) {
            RoleName::Gateway => [
                $this->rule('orbit:gateway-https', '443', 'any', 'orbit'),
            ],
            RoleName::Vpn => [],
            RoleName::Router, RoleName::Ingress, RoleName::AppDev => [],
            RoleName::AppProd => [$this->rule('orbit:app-prod-http', '80'), $this->rule('orbit:app-prod-https', '443')],
            RoleName::Metrics => [],
        };
    }

    private function wireguardMemberTrust(Node $node): UfwManagedRule
    {
        $destination = $this->wireguardIp($node);

        return new UfwManagedRule(
            new UfwRuleShape(
                comment: 'orbit:wireguard-members',
                action: 'allow',
                direction: 'in',
                source: 'any',
                destination: $destination,
                port: 'any',
                protocol: 'any',
                inInterface: 'orbit',
                outInterface: null,
                family: 'v4',
            ),
            [
                'sudo',
                'ufw',
                'allow',
                'in',
                'on',
                'orbit',
                'to',
                $destination,
                'comment',
                'orbit:wireguard-members',
            ],
        );
    }

    public function metricsExporter(Node $node, Node $metricsNode): UfwManagedRule
    {
        return $this->metricsRule(
            MetricsFootprint::ExporterFirewallComment,
            $this->metricsExporterAddress($metricsNode),
            $this->metricsExporterAddress($node),
            MetricsFootprint::ExporterPort,
        );
    }

    public function metricsGrafanaUpstream(Node $metricsNode, string $gatewayAddress): UfwManagedRule
    {
        if (filter_var($gatewayAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException(
                'metrics.publication_address_invalid',
                'Metrics publication requires valid WireGuard IPv4 addresses.',
                409,
            );
        }

        return $this->metricsRule(
            MetricsFootprint::PublicationFirewallComment,
            $gatewayAddress,
            $this->metricsPublicationAddress($metricsNode),
            MetricsFootprint::PublicationPort,
        );
    }

    private function rule(
        string $comment,
        string $port,
        ?string $destination = null,
        ?string $interface = null,
    ): UfwManagedRule {
        $destination ??= 'any';
        $on = $interface === null ? [] : ['on', $interface];

        return new UfwManagedRule(
            new UfwRuleShape(
                $comment,
                'allow',
                'in',
                'any',
                $destination,
                $port,
                'tcp',
                $interface,
                null,
                $destination === 'any' ? null : 'v4',
            ),
            [
                'sudo',
                'ufw',
                'allow',
                'in',
                ...$on,
                'proto',
                'tcp',
                'to',
                $destination,
                'port',
                $port,
                'comment',
                $comment,
            ],
        );
    }

    private function metricsRule(
        string $comment,
        string $source,
        string $destination,
        string $port,
    ): UfwManagedRule {
        return new UfwManagedRule(
            new UfwRuleShape(
                comment: $comment,
                action: 'allow',
                direction: 'in',
                source: $source,
                destination: $destination,
                port: $port,
                protocol: 'tcp',
                inInterface: MetricsFootprint::WireGuardInterface,
                outInterface: null,
                family: 'v4',
            ),
            [
                'sudo',
                'ufw',
                'allow',
                'in',
                'on',
                MetricsFootprint::WireGuardInterface,
                'proto',
                'tcp',
                'from',
                $source,
                'to',
                $destination,
                'port',
                $port,
                'comment',
                $comment,
            ],
        );
    }

    private function metricsExporterAddress(Node $node): string
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException(
                'metrics.exporter_address_invalid',
                "Node [{$node->name}] has no valid WireGuard IPv4 address.",
                409,
            );
        }

        return $address;
    }

    private function metricsPublicationAddress(Node $node): string
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException(
                'metrics.publication_address_invalid',
                'Metrics publication requires valid WireGuard IPv4 addresses.',
                409,
            );
        }

        return $address;
    }

    private function wireguardIp(Node $node): string
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new FirewallOperationException(
                step: 'host-firewall',
                errorCode: 'node.firewall_convergence_failed',
                message: "Node [{$node->name}] has no WireGuard address for a scoped firewall rule.",
            );
        }

        return $node->wireguard_ip;
    }
}
