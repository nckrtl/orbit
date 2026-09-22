<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskGroup;
use Closure;

interface TaskStartupLock
{
    /** @param Closure(): ?TaskGroup $operation */
    public function run(Closure $operation): ?TaskGroup;
}
