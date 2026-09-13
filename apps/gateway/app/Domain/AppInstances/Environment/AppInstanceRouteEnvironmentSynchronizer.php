<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Models\AppInstance;

interface AppInstanceRouteEnvironmentSynchronizer
{
    public function synchronizeRouteHostname(
        AppInstance $instance,
        AppInstanceEnvironmentRouteHostname $hostname,
    ): AppInstanceEnvironmentResult;
}
