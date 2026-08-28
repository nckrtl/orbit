<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;

interface NodeRoleToolIntentGuard
{
    /** @return list<string> */
    public function preview(Node $node, RoleName $role): array;

    public function assertSafe(Node $node, RoleName $role): void;

    public function retireUnsupported(Node $node): void;
}
