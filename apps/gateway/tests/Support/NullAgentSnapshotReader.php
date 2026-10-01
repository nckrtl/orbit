<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Node;

final readonly class NullAgentSnapshotReader implements AgentSnapshotReader
{
    public function snapshot(Node $node, string $threadId): ?array
    {
        return null;
    }
}
