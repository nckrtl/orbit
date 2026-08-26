<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Firewall\AllowFirewallRuleRequest;

final class AllowFirewallRuleCommand extends StoreFirewallRuleCommand
{
    #[\Override]
    protected $signature = 'firewall:allow
        {name : Stable firewall rule name}
        {--node= : Numeric target node ID}
        {--from= : Optional source IPv4/IPv6 address, CIDR, or any}
        {--protocol= : Optional tcp or udp protocol}
        {--port= : Destination port or ordered range}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Allow one named UFW rule through the gateway.';

    protected function request(
        int $nodeId,
        string $name,
        ?string $source,
        ?string $protocol,
        string $port,
    ): GatewayRequest {
        return new AllowFirewallRuleRequest($nodeId, $name, $source, $protocol, $port);
    }
}
