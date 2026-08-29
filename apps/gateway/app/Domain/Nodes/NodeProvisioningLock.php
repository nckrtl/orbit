<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use Closure;

interface NodeProvisioningLock
{
    /**
     * @template T
     *
     * @param Closure(): T $callback
     * @return T
     */
    public function run(string $nodeName, Closure $callback): mixed;
}
