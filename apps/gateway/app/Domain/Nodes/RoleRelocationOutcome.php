<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\NodeRole;

/**
 * A finished relocation. The move itself is complete; `$followUp` names work that still has to run
 * elsewhere, such as a Metrics reconcile that failed after the source was retracted.
 */
final readonly class RoleRelocationOutcome
{
    public function __construct(
        public NodeRole $assignment,
        public ?string $followUp = null,
    ) {}
}
