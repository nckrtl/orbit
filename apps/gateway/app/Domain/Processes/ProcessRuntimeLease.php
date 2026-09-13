<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Models\Process;
use Closure;

interface ProcessRuntimeLease
{
    /**
     * @template T
     *
     * @param  Closure(Process): T  $operation
     * @return T
     */
    public function run(Process $process, Closure $operation): mixed;
}
