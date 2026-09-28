<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Models\Instance;

interface AppInstanceEnvironmentSynchronizer
{
    public function execute(Instance $instance): AppInstanceEnvironmentResult;
}
