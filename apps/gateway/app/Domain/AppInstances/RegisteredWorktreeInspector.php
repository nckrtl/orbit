<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\App;
use App\Models\Node;

interface RegisteredWorktreeInspector
{
    public function inspect(
        Node $node,
        App $app,
        string $checkoutPath,
        string $effectiveRoot,
    ): RegisteredWorktreeObservation;
}
