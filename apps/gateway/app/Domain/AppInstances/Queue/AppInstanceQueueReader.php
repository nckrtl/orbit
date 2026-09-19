<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Queue;

use App\Models\Process;

interface AppInstanceQueueReader
{
    /**
     * Horizon's own report of its queues, read in the checkout of the App instance that owns the
     * `horizon` Process, as that Process's user and with its PHP binary.
     *
     * @param  'pending'|'completed'|'failed'  $state
     * @return array<array-key, mixed> The decoded report. It comes from inside the application, so the caller treats it as untrusted.
     */
    public function read(Process $horizon, string $state, int $limit): array;
}
