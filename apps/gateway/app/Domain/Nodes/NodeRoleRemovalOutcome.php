<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Metrics\ExporterDegradationReason;

/**
 * The result of removing one role, including what removal could not reach.
 *
 * A degraded removal is still a success — the role is gone from the Gateway —
 * but it is a different success, and the caller has to be able to say so.
 */
final readonly class NodeRoleRemovalOutcome
{
    /** @param list<string> $retained */
    public function __construct(
        public NodeRoleDependencySet $dependencies,
        public ?ExporterDegradationReason $degradation = null,
        public array $retained = [],
    ) {}
}
