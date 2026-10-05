<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Models\Instance;

interface ViteEnvironmentProjection
{
    /** Publish only the app-owned runtime file; never install dependencies or wake a host. */
    public function stageEnvironment(Instance $instance, string $app): void;
}
