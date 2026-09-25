<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Infrastructure\Nodes\NodeLocks;

/**
 * Renews the Node locks this process holds before each command it runs, on a Node over SSH or on the
 * Gateway, and refuses the command when one of them was lost to another operation.
 */
final readonly class LockRenewingProcessRunner implements ProcessRunner
{
    public function __construct(
        private ProcessRunner $runner,
        private NodeLocks $locks,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->locks->renewHeld();

        return $this->runner->run($invocation);
    }
}
