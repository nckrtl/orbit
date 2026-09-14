<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

use App\Models\AppInstance;
use App\Models\Process;

interface AppInstanceRuntimeReadiness
{
    /**
     * @param  list<Process>  $processes
     */
    public function waitUntilReady(AppInstance $instance, array $processes): void;
}
