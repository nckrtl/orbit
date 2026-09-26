<?php

declare(strict_types=1);

namespace App\Actions\Firewall;

use App\Models\FirewallRule;
use App\Models\Node;
use Illuminate\Support\Collection;

final readonly class ListFirewallRulesAction
{
    /** @return Collection<int, FirewallRule> */
    public function execute(Node $node): Collection
    {
        return $node->firewallRules()->with('node')->orderBy('name')->get();
    }

    /**
     * Every rule on the given Nodes in one read, for a client that shows the whole fleet.
     *
     * @param  list<int>  $nodeIds
     * @return Collection<int, FirewallRule>
     */
    public function executeForNodes(array $nodeIds): Collection
    {
        return FirewallRule::query()
            ->with('node')
            ->whereIn('node_id', $nodeIds)
            ->orderBy('node_id')
            ->orderBy('name')
            ->get();
    }
}
