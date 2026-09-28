<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\Instance;

interface DevelopmentAppInstanceProvisioner
{
    public function reserve(Instance $appInstance, ?string $domain): void;

    public function complete(
        Instance $appInstance,
        ?string $domain,
        bool $setupPending = false,
    ): Instance;
}
