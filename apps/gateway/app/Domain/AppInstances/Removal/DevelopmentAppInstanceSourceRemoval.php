<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Removal;

use App\Models\AppInstance;
use App\Models\AppInstanceRemovalMember;

interface DevelopmentAppInstanceSourceRemoval
{
    public function inspect(AppInstance $appInstance, bool $force): AppInstanceSourceInventory;

    public function prepare(AppInstanceRemovalMember $member): void;

    public function revalidate(AppInstanceRemovalMember $member): AppInstanceSourceRevalidationState;

    public function inspectRecorded(
        AppInstanceRemovalMember $member,
        AppInstanceSourceRevalidationState $state,
    ): AppInstanceSourceInventory;

    public function finalize(AppInstanceRemovalMember $member): string;
}
