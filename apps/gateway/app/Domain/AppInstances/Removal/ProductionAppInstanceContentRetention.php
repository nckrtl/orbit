<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Removal;

use App\Models\AppInstance;
use App\Models\AppInstanceRemovalMember;

interface ProductionAppInstanceContentRetention
{
    public function inventory(AppInstance $appInstance): AppInstanceSourceInventory;

    public function prepare(AppInstanceRemovalMember $member): void;

    public function revalidate(AppInstanceRemovalMember $member): void;

    public function finalize(AppInstanceRemovalMember $member): string;
}
