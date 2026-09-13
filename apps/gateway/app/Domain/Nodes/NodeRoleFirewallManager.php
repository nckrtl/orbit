<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;

interface NodeRoleFirewallManager
{
    public function convergeBase(Node $node, string $managedUser): void;

    public function converge(Node $node, RoleName $role, string $managedUser): void;

    public function remove(Node $node, RoleName $role, string $managedUser): void;

    /**
     * Reopens public SSH on a node that is leaving every role.
     *
     * Runs over WireGuard, because role convergence already closed the public
     * path, and never enables UFW: an inactive firewall is a failure here.
     */
    public function restorePublicSsh(Node $node, string $managedUser): void;
}
