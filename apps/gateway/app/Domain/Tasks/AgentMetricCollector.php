<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;

interface AgentMetricCollector
{
    public function collectMetrics(AgentThread $thread): void;
}
