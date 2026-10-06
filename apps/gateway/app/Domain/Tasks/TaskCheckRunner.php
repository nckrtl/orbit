<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;
use App\Models\Project;

/**
 * Runs a Project task check in a workspace as a detached process
 * ([ADR 0125](/decisions/0125-run-the-project-check-when-the-implementer-hands-off)).
 */
interface TaskCheckRunner
{
    /**
     * Starts the check. Setup steps run first, in order, as they do for a baseline check. With deliverables,
     * a passing check also records their evidence ([ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff)).
     * A command with fails_on_base also runs on the start commit with its paths overlaid (ADR 0163).
     * All check processes inherit TMPDIR=/tmp/orbit-check-<uid>-<random>, separate from
     * agent-<uid> used by agent tools. Neither role uses the other's restrictive tool caches.
     *
     * @param  list<array{name: string, command: string, timeout_seconds: int}>  $setup
     * @param  array{start: string|null, commands: list<array{id: string, command: string, directory: string, fails_on_base?: bool, paths?: list<string>}>}|null  $deliverables
     * @param  string|null  $command  the Project task check command, or null to run no command
     *
     * @throws TaskCheckException
     */
    public function start(Instance $instance, ?string $command, array $setup = [], ?array $deliverables = null): TaskCheckProcess;

    /** @throws TaskCheckException */
    public function read(Instance $instance, TaskCheckProcess $process): TaskCheckReading;

    /** @throws TaskCheckException */
    public function cancel(Instance $instance, TaskCheckProcess $process): void;

    /**
     * Reads HEAD and the working-tree hash the check stores, without copying an earlier check row
     * and without touching the Git index (ADR 0133).
     *
     * @throws TaskCheckException
     */
    public function snapshot(Instance $instance): TaskWorkspaceSnapshot;
}
