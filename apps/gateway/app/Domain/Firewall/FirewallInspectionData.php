<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

final readonly class FirewallInspectionData
{
    public function __construct(
        public FirewallBackendStatus $backend,
        public FirewallRuleInspectionStatus $rule,
    ) {}
}
