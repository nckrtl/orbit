<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use Closure;

interface MetricsCredentialOperationLock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(int $nodeId, Closure $operation): mixed;
}
