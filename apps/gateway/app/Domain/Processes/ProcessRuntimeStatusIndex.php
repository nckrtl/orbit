<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Infrastructure\Processes\PrometheusProcessRuntimeStatusIndex;
use App\Models\Process;
use Illuminate\Support\Collection;

/**
 * Resolves the live runtime status of many Processes at once.
 *
 * `ProcessRuntimeManager::status()` answers for one Process by asking its Node, which is the right
 * shape after a start, stop, or restart, and the wrong shape for a list: a fleet-wide screen asks
 * for every Process it knows, and one round trip per Process is what made that list cost seconds.
 *
 * @see PrometheusProcessRuntimeStatusIndex
 */
interface ProcessRuntimeStatusIndex
{
    /**
     * @param  Collection<int, Process>  $processes
     * @return array<int, string> Runtime status keyed by Process id, for every Process given.
     */
    public function statuses(Collection $processes): array;

    /**
     * Keeps a status the Gateway just observed on the Node after it started, stopped, or
     * restarted a Process, so a list does not report an older reading until the index's own
     * source has caught up.
     */
    public function remember(Process $process, string $status): void;
}
