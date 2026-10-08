<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Broadcasts `node.updated` when a Node starts or stops [updating](/reference/gateway-recovery#nodes-being-updated),
 * so a client flips its status without polling. A rollout visit or a release never fails because of it: every
 * failure is logged and swallowed.
 */
final readonly class NodeUpdateBroadcaster
{
    public function __construct(
        private RecordEventBroadcaster $broadcaster,
    ) {}

    public function node(?int $nodeId): void
    {
        if ($nodeId === null) {
            return;
        }

        $this->guarded(function () use ($nodeId): void {
            $node = Node::query()->with('roles')->find($nodeId);

            if ($node instanceof Node) {
                $this->broadcast($node);
            }
        });
    }

    /** Broadcasts the Node that holds the active `gateway` role, for a release that starts or ends. */
    public function gateway(): void
    {
        $this->guarded(function (): void {
            $nodes = Node::query()
                ->with('roles')
                ->where('status', LifecycleStatus::Active)
                ->whereHas('roles', static fn ($query) => $query
                    ->where('role', RoleName::Gateway)
                    ->where('status', LifecycleStatus::Active))
                ->get();

            foreach ($nodes as $node) {
                $this->broadcast($node);
            }
        });
    }

    private function broadcast(Node $node): void
    {
        $this->broadcaster->broadcast(
            RecordEventType::NodeUpdated,
            $node->id,
            NodeData::fromModel($node)->toArray(),
        );
    }

    /** @param callable(): void $operation */
    private function guarded(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            Log::warning('Failed to broadcast a Node update state.', ['exception' => $exception->getMessage()]);
        }
    }
}
