<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

final readonly class FirewallInspectionBatchData
{
    /** @param list<?FirewallRuleInspectionStatus> $rules */
    public function __construct(
        public FirewallBackendStatus $backend,
        public array $rules,
    ) {}
}
