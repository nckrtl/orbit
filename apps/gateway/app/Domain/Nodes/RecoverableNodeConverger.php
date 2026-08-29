<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;
use Closure;

interface RecoverableNodeConverger
{
    public function convergeRecoverably(
        Node $node,
        NodeProvisioningIdentity $identity,
        ?string $expectedSshHostFingerprint,
        Closure $completion,
        bool $rolelessOperator = false,
    ): void;
}
