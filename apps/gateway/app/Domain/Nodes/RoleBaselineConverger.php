<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;
use App\Models\NodeRole;

interface RoleBaselineConverger
{
    public function converge(Node $node, NodeRole $assignment): void;

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void;

    public function removeUnreachable(Node $node, NodeRole $assignment): void;
}
