<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Models\Instance;

interface AgentationSiteProjection
{
    public function project(Instance $instance): void;
}
