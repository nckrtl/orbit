<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;
use Closure;

interface RecoverableNodeConverger
{
    public function convergeRecoverably(
        Node $node,
        ?string $expectedSshHostFingerprint,
        Closure $completion,
    ): void;
}
