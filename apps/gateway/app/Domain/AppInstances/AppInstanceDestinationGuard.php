<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Domain\Nodes\Storage\StoragePath;
use App\Models\Node;

interface AppInstanceDestinationGuard
{
    public function assertUnoccupied(Node $node, StoragePath $destination): void;
}
