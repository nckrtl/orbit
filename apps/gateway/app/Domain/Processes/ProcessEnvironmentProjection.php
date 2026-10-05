<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Models\Instance;

interface ProcessEnvironmentProjection
{
    /** Re-render existing systemd units without starting, stopping, or restoring the checkout. */
    public function project(Instance $instance, int $exceptProcessId, ?string $app = null): void;
}
