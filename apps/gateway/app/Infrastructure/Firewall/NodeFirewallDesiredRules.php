<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Metrics\MetricsFirewallExpectationProvider;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class NodeFirewallDesiredRules
{
    public function __construct(
        private NodeFirewallRuleCatalog $catalog,
        private MetricsFirewallExpectationProvider $metrics,
    ) {}

    /**
     * The rules role and Metrics converge keep on the Node: baseline, each active role, then Metrics.
     *
     * @return list<DesiredFirewallRule>
     */
    public function forNode(Node $node): array
    {
        $rows = [];

        try {
            foreach ($this->catalog->desiredBaseline($node) as $rule) {
                $rows[] = new DesiredFirewallRule($rule->shape->comment, null, $rule->shape);
            }
        } catch (FirewallOperationException) {
            // A Node without a WireGuard address has no baseline to intend yet.
        }

        $assignments = $node->roles()
            ->where('status', LifecycleStatus::Active)
            ->orderBy('role')
            ->get();

        foreach ($assignments as $assignment) {
            /** @var NodeRole $assignment */
            try {
                foreach ($this->catalog->forRole($node, $assignment->role) as $rule) {
                    $rows[] = new DesiredFirewallRule(
                        $rule->shape->comment,
                        $assignment->role->value,
                        $rule->shape,
                    );
                }
            } catch (FirewallOperationException) {
                continue;
            }
        }

        try {
            foreach ($this->metrics->for($node) as $target) {
                $rows[] = $this->fromInspection($target);
            }
        } catch (FirewallOperationException|ResourceOperationException) {
            // Metrics rules need WireGuard addresses; skip them until those exist.
        }

        return $rows;
    }

    private function fromInspection(FirewallInspectionTarget $target): DesiredFirewallRule
    {
        $shape = $target->shape;

        return new DesiredFirewallRule(
            $shape->comment,
            'metrics',
            new UfwRuleShape(
                comment: $shape->comment,
                action: $shape->action,
                direction: $shape->direction,
                source: $shape->source,
                destination: $shape->destination,
                port: $shape->port,
                protocol: $shape->protocol,
                inInterface: $shape->inInterface,
                outInterface: $shape->outInterface,
                family: $shape->family,
            ),
        );
    }
}
