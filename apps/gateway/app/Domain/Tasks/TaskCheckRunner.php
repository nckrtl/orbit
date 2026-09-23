<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

/**
 * Runs the Project's `composer check` in a task workspace as a detached process
 * ([ADR 0123](/decisions/0123-run-the-project-check-when-the-implementer-hands-off)).
 */
interface TaskCheckRunner
{
    /** @throws TaskCheckException */
    public function start(AppInstance $instance): TaskCheckProcess;

    /** @throws TaskCheckException */
    public function read(AppInstance $instance, TaskCheckProcess $process): TaskCheckReading;

    /** @throws TaskCheckException */
    public function cancel(AppInstance $instance, TaskCheckProcess $process): void;
}
