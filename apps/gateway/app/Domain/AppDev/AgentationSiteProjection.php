<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Models\AppInstance;

interface AgentationSiteProjection
{
    public function project(AppInstance $instance): void;
}
