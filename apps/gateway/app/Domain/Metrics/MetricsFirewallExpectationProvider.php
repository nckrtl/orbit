<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Firewall\FirewallInspectionTarget;
use App\Models\Node;

interface MetricsFirewallExpectationProvider
{
    /** @return list<FirewallInspectionTarget> */
    public function for(Node $node): array;
}
