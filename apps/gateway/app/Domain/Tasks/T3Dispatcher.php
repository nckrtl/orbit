<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Node;

/**
 * Posts one stock T3 orchestration command to the Node that owns a Task workspace.
 *
 * The body is a flat command. Implementations must send `headers` as an empty
 * array and must not nest the command under another key.
 */
interface T3Dispatcher
{
    /**
     * @param  array<string, mixed>  $command
     * @return array{sequence: int, thread_id: string}
     */
    public function dispatch(Node $node, array $command): array;
}
