<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

/**
 * ADR 0124 and ADR 0169: gives a task workspace Orbit MCP at `/mcp/search`, whatever MCP the Node's agent is configured with.
 */
interface TaskPlannerMcp
{
    /** False when the workspace could not be prepared. A repository that tracks its own `.mcp.json` is left as it is. */
    public function install(AppInstance $instance): bool;

    /**
     * Writes the search endpoint file when the workspace has no `.mcp.json`.
     * An existing file, tracked or not, stays unchanged. False when that file is missing and could not be written.
     */
    public function installWhenMissing(AppInstance $instance): bool;
}
