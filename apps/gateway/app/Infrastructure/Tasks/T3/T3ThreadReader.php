<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

use App\Models\Node;

/**
 * Reads one T3 thread snapshot from the Node that owns a Task workspace.
 *
 * A no-op or refused read returns null. Implementations must not throw for a
 * down T3 server.
 */
interface T3ThreadReader
{
    /**
     * @return array<string, mixed>|null
     */
    public function snapshot(Node $node, string $threadId): ?array;
}
