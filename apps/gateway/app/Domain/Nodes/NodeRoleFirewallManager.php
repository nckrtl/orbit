<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;

interface NodeRoleFirewallManager
{
    public function convergeBase(Node $node, string $managedUser): void;

    public function converge(Node $node, RoleName $role, string $managedUser): void;

    public function remove(Node $node, RoleName $role, string $managedUser): void;
}
