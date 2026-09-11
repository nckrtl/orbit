<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\DeploymentLayout;

use App\Models\AppInstance;

interface ProductionPhpRuntimeAdopter
{
    public function adopt(AppInstance $appInstance, string $initialLocalTuning): void;
}
