<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

enum FirewallRuleInspectionStatus: string
{
    case Exact = 'exact';
    case Missing = 'missing';
    case Drift = 'drift';
}
