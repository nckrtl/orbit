<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Models\Node;
use App\Models\NodeRole;

interface WebSocketRuntimeLifecycle
{
    public function converge(Node $node, NodeRole $assignment, WebSocketCredentials $credentials): void;

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void;

    public function health(Node $node): bool;
}
