<?php

declare(strict_types=1);

namespace App\Domain\Instances\Removal;

use App\Models\Instance;
use App\Models\InstanceRemovalMember;

interface ProductionInstanceContentRetention
{
    public function inventory(Instance $instance): InstanceSourceInventory;

    public function prepare(InstanceRemovalMember $member): void;

    public function revalidate(InstanceRemovalMember $member): void;

    public function finalize(InstanceRemovalMember $member): string;
}
