<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;

interface ManagedUserAccountResolver
{
    public function resolve(Node $node): ManagedUserAccount;
}
