<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface ProductionPhpRuntimeManager
{
    public function converge(Instance $instance): void;

    public function convergeMonitoring(Instance $instance, bool $enabled): void;

    public function refreshCache(Instance $instance): void;

    public function remove(Instance $instance): void;
}
