<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

/**
 * Runs the Project's `composer check` in a task workspace as a detached process
 * ([ADR 0125](/decisions/0125-run-the-project-check-when-the-implementer-hands-off)).
 */
interface TaskCheckRunner
{
    /**
     * Starts the check. Setup steps run first, in order, as they do for a baseline check.
     *
     * @param  list<array{name: string, command: string, timeout_seconds: int}>  $setup
     *
     * @throws TaskCheckException
     */
    public function start(AppInstance $instance, array $setup = []): TaskCheckProcess;

    /** @throws TaskCheckException */
    public function read(AppInstance $instance, TaskCheckProcess $process): TaskCheckReading;

    /** @throws TaskCheckException */
    public function cancel(AppInstance $instance, TaskCheckProcess $process): void;
}
