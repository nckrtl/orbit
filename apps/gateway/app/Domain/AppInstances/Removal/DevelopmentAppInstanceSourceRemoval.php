<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Removal;

use App\Models\Instance;

interface DevelopmentAppInstanceSourceRemoval
{
    public function inspect(
        Instance $appInstance,
        bool $force,
        bool $inspectContent = true,
    ): AppInstanceSourceInventory;

    public function remove(
        Instance $appInstance,
        AppInstanceSourceInventory $inventory,
        bool $force,
    ): void;
}
