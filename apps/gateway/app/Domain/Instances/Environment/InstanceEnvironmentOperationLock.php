<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use Closure;

interface InstanceEnvironmentOperationLock
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
