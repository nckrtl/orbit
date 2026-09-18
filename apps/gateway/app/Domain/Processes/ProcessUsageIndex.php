<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Infrastructure\Processes\PrometheusProcessUsageIndex;
use App\Models\Process;
use Illuminate\Support\Collection;

/**
 * Resolves live CPU and memory usage for many Processes at once, the same shape
 * `ProcessRuntimeStatusIndex` resolves runtime status in: one fleet-wide query pair instead of one
 * round trip per Process.
 *
 * @see PrometheusProcessUsageIndex
 */
interface ProcessUsageIndex
{
    /**
     * @param  Collection<int, Process>  $processes
     * @return array<int, array{cpu: ?float, memory_bytes: ?int}> Usage keyed by Process id, for
     *                                                            every Process given. Either field
     *                                                            is null when cAdvisor has no
     *                                                            series for that Process — it is
     *                                                            not running, or usage could not
     *                                                            be read — never a false zero.
     */
    public function usage(Collection $processes): array;
}
