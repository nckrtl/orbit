<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

enum ExporterSelectionReason: string
{
    case MetricsNode = 'metrics_node';
    case ExplicitEnabled = 'explicit_enabled';
    case RoleDefault = 'role_default';
    case ExplicitDisabled = 'explicit_disabled';
    case RolelessDefaultExcluded = 'roleless_default_excluded';
}
