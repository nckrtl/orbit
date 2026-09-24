<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\Process;
use SensitiveParameter;

/**
 * Keeps Plausible's ClickHouse configuration on the operator's ClickHouse Process: the files on its
 * Node, their read-only mounts, and a restart when either changed. It creates no database or user.
 */
interface AnalyticsClickhouseConfigurationManager
{
    public function converge(#[SensitiveParameter] Process $clickhouse): void;
}
