<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Models\Node;

/** The verify step of a fleet rollout Node: a Doctor baseline before the steps and the check after them. */
interface FleetNodeVerification
{
    /** @return list<string> */
    public function baseline(Node $node): array;

    /**
     * @param  list<string>  $baseline
     * @param  list<string>  $tolerated  Issue codes that a skipped footprint artifact explains.
     * @return array{passed: bool, new_issues: list<array<string, mixed>>, preexisting_issues: list<string>, agent_version: ?string, presence: string}
     */
    public function verify(Node $node, array $baseline, string $pinnedAgentVersion, array $tolerated = []): array;
}
