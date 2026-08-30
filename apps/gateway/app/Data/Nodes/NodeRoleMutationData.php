<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use App\Domain\Nodes\NodeRoleRemovalOutcome;
use App\Domain\Nodes\NodeSideResidue;
use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\NodeRole;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class NodeRoleMutationData extends Data
{
    /**
     * @param list<string> $retainedOnNode
     *
     * @mago-expect lint:excessive-parameter-list The mutation reports every outcome the operator has to act on.
     */
    public function __construct(
        public int $nodeId,
        public string $nodeName,
        public string $role,
        public ?NodeRoleAssignmentData $assignment,
        public bool $removed,
        public ?string $degradation = null,
        public array $retainedOnNode = [],
        public ?string $followUp = null,
    ) {}

    public static function added(Node $node, NodeRole $assignment): self
    {
        return new self(
            nodeId: $node->id,
            nodeName: $node->name,
            role: $assignment->role->value,
            assignment: NodeRoleAssignmentData::fromModel($assignment),
            removed: false,
        );
    }

    public static function removed(Node $node, RoleName $role, NodeRoleRemovalOutcome $outcome): self
    {
        return new self(
            nodeId: $node->id,
            nodeName: $node->name,
            role: $role->value,
            assignment: null,
            removed: true,
            degradation: $outcome->degradation?->value,
            retainedOnNode: $outcome->retained,
            // The node keeps its registration, so only this role's leftovers
            // are stranded; the node-local wipe would take managed state too.
            followUp: $outcome->retained === [] ? null : NodeSideResidue::FOLLOW_UP_ROLE_REMOVED,
        );
    }
}
