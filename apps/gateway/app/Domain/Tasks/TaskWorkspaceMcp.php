<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

/**
 * ADR 0178 and ADR 0169: gives a task workspace Orbit MCP at `/mcp/search`, whatever MCP the Node's agent is configured with.
 */
interface TaskWorkspaceMcp
{
    /**
     * Writes the search endpoint file when the workspace has no `.mcp.json`.
     * An existing file, tracked or not, stays unchanged. False when that file is missing and could not be written.
     */
    public function installWhenMissing(Instance $instance): bool;
}
