<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

enum MetricsService: string
{
    case Prometheus = 'prometheus';
    case Grafana = 'grafana';
}
