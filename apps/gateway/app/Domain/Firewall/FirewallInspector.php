<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Models\FirewallRule;

interface FirewallInspector
{
    public function inspect(FirewallRule $rule): FirewallInspectionData;
}
