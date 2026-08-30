<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;

interface NodeConverger
{
    public function converge(
        Node $node,
        NodeProvisioningIdentity $identity,
        ?string $expectedSshHostFingerprint = null,
        bool $rolelessOperator = false,
    ): void;
}
