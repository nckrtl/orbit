<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Infrastructure\Firewall\UfwRuleOwnership;

final readonly class MetricsExporterState
{
    /**
     * @param  ?string  $staleFirewallSource  The source address of an Orbit rule that admits a former Metrics
     *                                        Node, with `firewallOwnership` Drift. Convergence re-points it.
     */
    public function __construct(
        public ?string $configuration,
        public bool $serviceActive,
        public UfwRuleOwnership $firewallOwnership,
        public string $firewallStatus = '',
        public ?string $staleFirewallSource = null,
    ) {}

    /** The source the Orbit rule admits, or null when there is none. */
    public function firewallSource(string $expectedSource): ?string
    {
        return match ($this->firewallOwnership) {
            UfwRuleOwnership::Exact => $expectedSource,
            UfwRuleOwnership::Missing => null,
            UfwRuleOwnership::Drift => $this->staleFirewallSource,
        };
    }
}
