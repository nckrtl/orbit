<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Node;

interface TaskAgentStream
{
    /** @return iterable<array<string, mixed>> */
    public function events(Node $node, string $threadId, ?int $afterSequence): iterable;
}
