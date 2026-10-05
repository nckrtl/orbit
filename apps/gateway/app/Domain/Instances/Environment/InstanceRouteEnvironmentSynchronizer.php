<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Models\Instance;

interface InstanceRouteEnvironmentSynchronizer
{
    public function synchronizeRouteDomain(
        Instance $instance,
        InstanceEnvironmentRouteDomain $domain,
        ?string $app = null,
    ): InstanceEnvironmentResult;
}
