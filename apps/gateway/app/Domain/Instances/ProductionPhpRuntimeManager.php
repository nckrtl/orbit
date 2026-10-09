<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;
use App\Models\Route;

interface ProductionPhpRuntimeManager
{
    /** `$activating` is a Route with a web root that is being created, so its pool already counts. */
    public function converge(Instance $instance, ?Route $activating = null): void;

    public function convergeMonitoring(Instance $instance, bool $enabled): void;

    public function refreshCache(Instance $instance): void;

    public function remove(Instance $instance): void;
}
