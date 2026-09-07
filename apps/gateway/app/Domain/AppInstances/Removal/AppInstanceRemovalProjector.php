<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Removal;

use App\Models\AppInstanceRemovalMember;

interface AppInstanceRemovalProjector
{
    public function clearRouteTarget(AppInstanceRemovalMember $member): string;

    public function cleanupRuntime(AppInstanceRemovalMember $member): void;
}
