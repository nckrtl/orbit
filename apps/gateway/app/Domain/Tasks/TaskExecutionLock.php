<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Closure;

/** Serializes work admission with completion authorization, without holding a database transaction. */
interface TaskExecutionLock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function synchronized(int $groupId, Closure $operation): mixed;
}
