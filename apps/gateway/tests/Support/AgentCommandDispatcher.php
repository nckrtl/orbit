<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Node;

interface AgentCommandDispatcher
{
    /**
     * @param  array<string, mixed>  $command
     * @return array{sequence: int, thread_id: string}
     */
    public function dispatch(Node $node, array $command): array;
}
