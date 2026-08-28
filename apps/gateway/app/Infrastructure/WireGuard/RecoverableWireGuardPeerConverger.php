<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Infrastructure\Ssh\SshConnection;
use App\Models\Node;
use Closure;

interface RecoverableWireGuardPeerConverger
{
    public function convergeRecoverably(Node $node, SshConnection $connection, Closure $completion): void;
}
