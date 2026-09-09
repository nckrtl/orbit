<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

interface FirewallInspector
{
    /** @param non-empty-list<FirewallInspectionTarget> $targets */
    public function inspect(array $targets): FirewallInspectionBatchData;
}
