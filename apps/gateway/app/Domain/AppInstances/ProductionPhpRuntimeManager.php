<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;

interface ProductionPhpRuntimeManager
{
    public function converge(AppInstance $appInstance): void;

    public function refreshCache(AppInstance $appInstance): void;

    public function remove(AppInstance $appInstance): void;
}
