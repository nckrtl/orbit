<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build\Sources;

use App\Domain\Nodes\RoleName;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\NodeCaddySiteUnavailable;
use App\Infrastructure\Gateway\GatewayCaddyConfigRenderer;
use App\Models\Node;

/**
 * The Gateway web site on the Node that holds the `gateway` role, named as Gateway web convergence names it.
 */
final readonly class GatewayWebCaddySiteSource implements NodeCaddySiteSource
{
    public function __construct(
        private GatewayCaddyConfigRenderer $renderer,
        private VpnSettings $vpn,
        private ?string $checkoutPath = null,
        private ?string $webRoot = null,
    ) {}

    public function sites(Node $node): array
    {
        if (! CaddySiteRoles::nodeServes($node->id, RoleName::Gateway)) {
            return [];
        }

        $address = $node->wireguard_ip;

        if (! is_string($address) || $address === '') {
            throw NodeCaddySiteUnavailable::wireGuardAddressMissing('gateway', $node->name);
        }

        $hostname = "{$node->name}.{$this->vpn->domain()}";
        $checkout = $this->checkoutPath ?? rtrim((string) config('orbit.gateway_checkout'), '/');
        $webRoot = $this->webRoot ?? (string) config('orbit.gateway_web');

        return [new CaddySite(
            source: 'gateway',
            name: $hostname,
            listener: CaddyListenerRule::WireGuard,
            hosts: [$hostname, $address],
            port: 443,
            body: $this->renderer->render($hostname, $address, $checkout, $webRoot),
        )];
    }
}
