<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use Closure;

interface DevelopmentProjectionOperationLock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(Closure $operation): mixed;
}
