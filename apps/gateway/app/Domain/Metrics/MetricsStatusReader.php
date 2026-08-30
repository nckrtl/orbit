<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Data\Metrics\MetricsStatusData;

interface MetricsStatusReader
{
    public function status(): MetricsStatusData;
}
