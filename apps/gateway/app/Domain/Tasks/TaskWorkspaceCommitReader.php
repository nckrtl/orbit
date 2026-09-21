<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use Carbon\CarbonImmutable;

/**
 * Reads the commit times of the shared task checkout, newest first.
 *
 * A refused or unavailable git read returns null. Implementations must not
 * throw for an unreachable Node.
 */
interface TaskWorkspaceCommitReader
{
    /** @return list<CarbonImmutable>|null */
    public function commitTimes(AppInstance $instance): ?array;
}
