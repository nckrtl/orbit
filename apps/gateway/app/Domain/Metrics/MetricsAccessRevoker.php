<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

interface MetricsAccessRevoker
{
    public function revoke(): void;
}
