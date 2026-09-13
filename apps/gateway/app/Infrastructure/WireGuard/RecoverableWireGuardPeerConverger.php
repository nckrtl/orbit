<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Infrastructure\Ssh\SshConnection;
use App\Models\Node;
use Closure;

interface RecoverableWireGuardPeerConverger
{
    /**
     * Publishes the peer as a retained transaction, runs the completion, then
     * commits; any failure rolls the peer back first.
     *
     * The completion receives a registrar it may call with the verified tunnel
     * connection, which then carries the commit and any later rollback.
     *
     * @param  Closure(Closure(SshConnection): void=): void  $completion
     */
    public function convergeRecoverably(
        Node $node,
        SshConnection $connection,
        Closure $completion,
        bool $rolelessOperator = false,
    ): void;
}
