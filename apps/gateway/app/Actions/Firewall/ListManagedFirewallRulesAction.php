<?php

declare(strict_types=1);

namespace App\Actions\Firewall;

use App\Infrastructure\Firewall\DesiredFirewallRule;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Models\Node;

final readonly class ListManagedFirewallRulesAction
{
    public function __construct(private NodeFirewallDesiredRules $desired) {}

    /**
     * The rules Orbit itself keeps on the Node: WireGuard trust, public SSH recovery only while
     * no active role has closed it, then each active role and Metrics. They come from the same
     * catalog that convergence applies, so this is what Orbit intends, not a reading of the Node.
     *
     * @return list<array{name: string, role: ?string, action: string, source: string, destination: string, port: string, protocol: string, interface: ?string}>
     */
    public function execute(Node $node): array
    {
        return array_map(
            static fn (DesiredFirewallRule $rule): array => $rule->toListRow(),
            $this->desired->forNode($node),
        );
    }
}
