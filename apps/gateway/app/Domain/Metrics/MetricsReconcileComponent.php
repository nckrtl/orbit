<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

enum MetricsReconcileComponent: string
{
    case Exporter = 'exporter';
    case Cadvisor = 'cadvisor';
    case Runtime = 'runtime';
}
