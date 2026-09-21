<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

use App\Models\Node;

interface T3Stream
{
    /** @return iterable<array<string, mixed>> */
    public function events(Node $node, string $threadId, ?int $afterSequence): iterable;
}
