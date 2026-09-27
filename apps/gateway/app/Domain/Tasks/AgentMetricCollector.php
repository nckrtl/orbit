<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;

interface AgentMetricCollector
{
    /** Returns true when the stream delivered a valid snapshot or event for this collection. */
    public function collectMetrics(AgentThread $thread): bool;
}
