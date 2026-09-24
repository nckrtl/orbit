<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Models\Node;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class NodeAgentRoleConverger
{
    public function __construct(
        private NodeAgentRuntime $agent,
        private ManagedNodeEligibility $eligibility,
    ) {}

    public function converge(Node $node): void
    {
        if (! $this->eligibility->allows($node)) {
            return;
        }

        try {
            $this->agent->converge($node);
        } catch (Throwable $exception) {
            Log::warning('Node agent convergence failed; role convergence will continue.', [
                'node_id' => $node->id,
                'node_name' => $node->name,
                'error' => $exception::class,
            ]);
        }
    }
}
