<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\Instance;

interface AppInstanceActivationHook
{
    public function complete(Instance $appInstance, ?string $requestedName): void;
}
