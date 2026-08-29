<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Nodes\NodeProvisioningException;
use App\Models\Node;
use Closure;

interface ToolManagerMaterializer
{
    public function converge(Node $node, ToolManagerName ...$managerNames): void;

    /** @param Closure(NodeProvisioningException): void $onFailure */
    public function convergeWithFailureHandler(Node $node, Closure $onFailure, ToolManagerName ...$managerNames): void;
}
