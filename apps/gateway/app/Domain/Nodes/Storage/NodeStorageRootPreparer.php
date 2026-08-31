<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Domain\Nodes\ManagedUserAccount;
use App\Models\Node;

interface NodeStorageRootPreparer
{
    public function inspect(Node $node, ManagedUserAccount $account, StoragePath $path): void;

    public function prepare(Node $node, ManagedUserAccount $account, EffectiveStorageRoots $roots): void;
}
