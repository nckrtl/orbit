<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;

interface NodeRoleToolIntentGuard
{
    /** @return list<string> */
    public function preview(Node $node, RoleName $role): array;

    /** @return list<string> */
    public function retirementPreview(Node $node, RoleName $role): array;

    public function assertRemovalSafe(Node $node, RoleName $role): void;

    public function retireUnsupportedManagers(Node $node): void;
}
