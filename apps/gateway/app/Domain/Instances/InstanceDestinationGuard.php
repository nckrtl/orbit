<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\Storage\StoragePath;
use App\Models\Node;

interface InstanceDestinationGuard
{
    public function assertUnoccupied(Node $node, StoragePath $destination): void;
}
