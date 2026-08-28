<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use Closure;

interface ToolManagerScopeLock
{
    /**
     * @template T
     *
     * @param Closure(): T $callback
     * @return T
     */
    public function run(int $nodeId, ToolManagerName $manager, Closure $callback): mixed;
}
