<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Models\FirewallRule;
use App\Models\Node;

final readonly class FirewallInspectionTarget
{
    public function __construct(
        public Node $node,
        public FirewallInspectionShape $shape,
        public int|string $resourceId,
        public string $resourceName,
    ) {}

    public static function fromRule(FirewallRule $rule): self
    {
        $rule->loadMissing('node');
        $family = FirewallSource::family($rule->source);

        return new self(
            node: $rule->node,
            shape: new FirewallInspectionShape(
                comment: "orbit:node:{$rule->node_id}:firewall:{$rule->name}",
                action: $rule->action->value,
                direction: 'in',
                source: $rule->source,
                destination: 'any',
                port: $rule->port,
                protocol: $rule->protocol,
                inInterface: null,
                outInterface: null,
                family: $family === 'both' ? null : $family,
            ),
            resourceId: $rule->id,
            resourceName: $rule->name,
        );
    }
}
