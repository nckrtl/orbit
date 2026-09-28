<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Models\Instance;

interface AppInstanceRouteEnvironmentSynchronizer
{
    public function synchronizeRouteDomain(
        Instance $instance,
        AppInstanceEnvironmentRouteDomain $domain,
    ): AppInstanceEnvironmentResult;
}
