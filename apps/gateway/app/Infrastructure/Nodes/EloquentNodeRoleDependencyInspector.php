<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\RoleName;
use App\Models\Node;

final readonly class EloquentNodeRoleDependencyInspector implements NodeRoleDependencyInspector
{
    public function inspect(Node $node, RoleName $role): NodeRoleDependencySet
    {
        return new NodeRoleDependencySet([], [], [], []);
    }
}
