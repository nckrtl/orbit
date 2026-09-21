<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Node;

final readonly class NullT3ThreadReader implements T3ThreadReader
{
    public function snapshot(Node $node, string $threadId): ?array
    {
        return null;
    }
}
