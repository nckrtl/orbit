<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

interface FirewallInspector
{
    public function inspect(FirewallInspectionTarget $target): FirewallInspectionData;
}
