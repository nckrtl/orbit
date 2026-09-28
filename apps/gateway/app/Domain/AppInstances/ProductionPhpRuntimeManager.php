<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\Instance;

interface ProductionPhpRuntimeManager
{
    public function converge(Instance $appInstance): void;

    public function convergeMonitoring(Instance $appInstance, bool $enabled): void;

    public function refreshCache(Instance $appInstance): void;

    public function remove(Instance $appInstance): void;
}
