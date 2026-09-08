<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Removal;

use App\Models\AppInstance;

interface DevelopmentAppInstanceSourceRemoval
{
    public function inspect(AppInstance $appInstance, bool $force): AppInstanceSourceInventory;

    public function remove(
        AppInstance $appInstance,
        AppInstanceSourceInventory $inventory,
        bool $force,
    ): void;
}
