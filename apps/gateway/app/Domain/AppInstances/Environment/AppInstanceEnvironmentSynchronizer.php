<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Models\AppInstance;

interface AppInstanceEnvironmentSynchronizer
{
    public function execute(AppInstance $instance): AppInstanceEnvironmentResult;
}
