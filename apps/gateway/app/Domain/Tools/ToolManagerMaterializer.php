<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Models\Node;

interface ToolManagerMaterializer
{
    public function converge(Node $node): void;
}
