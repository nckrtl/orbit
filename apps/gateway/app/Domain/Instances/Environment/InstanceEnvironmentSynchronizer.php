<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Models\Instance;

interface InstanceEnvironmentSynchronizer
{
    public function execute(Instance $instance): InstanceEnvironmentResult;
}
