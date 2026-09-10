<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Models\Process;

interface ProcessRuntimeManager
{
    /** Refuse a desired-running admission before the Process row or runtime changes. */
    public function assertCanStart(Process $process): void;

    /** Reconcile the runtime artifact and its persisted desired state under one runtime lock. */
    public function converge(Process $process): void;

    public function start(Process $process): void;

    public function stop(Process $process): void;

    public function restart(Process $process): void;

    public function remove(Process $process): void;

    public function status(Process $process): string;

    public function logs(Process $process, int $lines): string;
}
