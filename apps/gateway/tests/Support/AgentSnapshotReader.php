<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Node;

interface AgentSnapshotReader
{
    /** @return array<string, mixed>|null */
    public function snapshot(Node $node, string $threadId): ?array;
}
