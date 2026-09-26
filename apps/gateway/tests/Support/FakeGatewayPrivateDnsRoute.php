<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Nodes\GatewayPrivateDnsRoute;
use App\Domain\Nodes\NodeRoleFollowUpReport;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Models\Node;

/**
 * Records the Gateway private DNS route steps instead of running them over SSH.
 */
final class FakeGatewayPrivateDnsRoute implements GatewayPrivateDnsRoute
{
    /** @var list<string> */
    public array $events = [];

    /** A follow-up the next route convergence records, as a failed route step does. */
    public ?string $followUp = null;

    public ?NodeRoleOperationException $removeFailure = null;

    public function convergeRoute(Node $node): void
    {
        $this->events[] = "converge:{$node->name}";

        if ($this->followUp !== null) {
            app(NodeRoleFollowUpReport::class)->record($this->followUp);
        }
    }

    public function removeRoute(Node $node): void
    {
        $this->events[] = "remove:{$node->name}";

        if ($this->removeFailure instanceof NodeRoleOperationException) {
            throw $this->removeFailure;
        }
    }
}
