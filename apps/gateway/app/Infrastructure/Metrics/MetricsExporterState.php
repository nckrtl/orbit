<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Infrastructure\Firewall\UfwRuleOwnership;

final readonly class MetricsExporterState
{
    public function __construct(
        public ?string $configuration,
        public bool $serviceActive,
        public UfwRuleOwnership $firewallOwnership,
        public string $firewallStatus = '',
    ) {}
}
