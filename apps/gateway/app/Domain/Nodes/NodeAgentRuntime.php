<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;

interface NodeAgentRuntime
{
    public function converge(Node $node): void;
}
