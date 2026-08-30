<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Models\Node;

interface GatewaySelfAccessConverger
{
    public function converge(Node $node): void;
}
