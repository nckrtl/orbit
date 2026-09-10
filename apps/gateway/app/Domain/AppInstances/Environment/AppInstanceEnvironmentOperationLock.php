<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use Closure;

interface AppInstanceEnvironmentOperationLock
{
    /**
     * @template T
     *
     * @param  list<int>  $appInstanceIds
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(array $appInstanceIds, Closure $operation): mixed;
}
