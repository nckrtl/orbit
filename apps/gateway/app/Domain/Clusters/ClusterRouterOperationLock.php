<?php

declare(strict_types=1);

namespace App\Domain\Clusters;

use Closure;

interface ClusterRouterOperationLock
{
    /**
     * @template T
     *
     * @param Closure(): T $operation
     * @return T
     */
    public function run(int $clusterId, Closure $operation): mixed;
}
