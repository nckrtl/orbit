<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

/**
 * Runs a Project task check in a workspace as a detached process
 * ([ADR 0125](/decisions/0125-run-the-project-check-when-the-implementer-hands-off)).
 */
interface TaskCheckRunner
{
    /**
     * Starts the check. Setup steps run first, in order, as they do for a baseline check. With deliverables,
     * a passing check also records their evidence ([ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff)).
     *
     * @param  list<array{name: string, command: string, timeout_seconds: int}>  $setup
     * @param  array{start: string|null, tests: list<array{id: string, project: string, file: string}>, commands: list<array{id: string, command: string, directory: string}>}|null  $deliverables
     * @param  string|null  $command  the command to run, or null for a no-op check
     *
     * @throws TaskCheckException
     */
    public function start(AppInstance $instance, array $setup = [], ?array $deliverables = null, ?string $command = 'composer check'): TaskCheckProcess;

    /** @throws TaskCheckException */
    public function read(AppInstance $instance, TaskCheckProcess $process): TaskCheckReading;

    /** @throws TaskCheckException */
    public function cancel(AppInstance $instance, TaskCheckProcess $process): void;
}
