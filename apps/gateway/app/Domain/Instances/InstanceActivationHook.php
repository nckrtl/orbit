<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface InstanceActivationHook
{
    public function complete(Instance $instance, ?string $requestedName): void;
}
