<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Nodes\NodeRoleToolIntentGuard;
use App\Domain\Nodes\RoleName;
use App\Models\Node;

final readonly class EloquentNodeRoleToolIntentGuard implements NodeRoleToolIntentGuard
{
    /** @return list<string> */
    public function preview(Node $node, RoleName $role): array
    {
        return [];
    }

    public function assertRemovalSafe(Node $node, RoleName $role): void {}

    /** @return list<string> */
    public function retirementPreview(Node $node, RoleName $role): array
    {
        return [];
    }

    public function retireUnsupportedManagers(Node $node): void {}
}
