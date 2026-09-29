<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

use App\Models\Instance;
use App\Models\Process;

interface InstanceRuntimeReadiness
{
    /**
     * @param  list<Process>  $processes
     */
    public function waitUntilReady(Instance $instance, array $processes): void;
}
