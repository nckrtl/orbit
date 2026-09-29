<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use Closure;

interface ProcessAdmissionLock
{
    /**
     * @template T
     *
     * @param  list<int>  $instanceIds
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(array $instanceIds, Closure $operation): mixed;
}
