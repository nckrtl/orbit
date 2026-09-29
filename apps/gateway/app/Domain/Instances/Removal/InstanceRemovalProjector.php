<?php

declare(strict_types=1);

namespace App\Domain\Instances\Removal;

use App\Models\InstanceRemovalMember;

interface InstanceRemovalProjector
{
    public function clearRouteTarget(InstanceRemovalMember $member): string;

    public function cleanupRuntime(InstanceRemovalMember $member): void;
}
