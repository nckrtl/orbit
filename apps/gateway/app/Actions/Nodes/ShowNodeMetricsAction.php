<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\Nodes\Metrics\NodeMetricsReader;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

final readonly class ShowNodeMetricsAction
{
    public function __construct(private NodeMetricsReader $reader) {}

    /** @return array<string, mixed> */
    public function execute(Node $node): array
    {
        if ($node->status !== LifecycleStatus::Active || ! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException(
                errorCode: 'node.metrics_unavailable',
                message: "Node [{$node->name}] is not an active, WireGuard-managed Node.",
                status: 422,
            );
        }

        return $this->reader->read($node);
    }
}
