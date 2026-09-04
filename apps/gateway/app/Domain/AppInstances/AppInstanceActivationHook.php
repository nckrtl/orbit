<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;

interface AppInstanceActivationHook
{
    public function complete(AppInstance $appInstance, ?string $requestedName): void;
}
