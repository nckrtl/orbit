<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

/**
 * Carries a convergence step that failed without failing the role out to the role response.
 *
 * `RoleBaseline::converge()` returns nothing, so a step that must not fail the role, such as the
 * Gateway machine's private DNS route, would otherwise reach the operator only through the log and
 * Doctor. The baseline records the follow-up here, and the role response returns it as `follow_up`.
 *
 * The recorder is shared for one request and is deliberately not readonly.
 */
final class NodeRoleFollowUpReport
{
    /** @var list<string> */
    private array $followUps = [];

    public function record(string $followUp): void
    {
        $this->followUps[] = $followUp;
    }

    /** Reads the recorded follow-ups as one text and clears them, so no later call can reuse them. */
    public function take(): ?string
    {
        $followUps = $this->followUps;
        $this->followUps = [];

        return $followUps === [] ? null : implode(' ', $followUps);
    }
}
