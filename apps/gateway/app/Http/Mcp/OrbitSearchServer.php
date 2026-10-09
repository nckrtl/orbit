<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use Laravel\Mcp\Server\Tools\ToolSearch;

/**
 * The same catalogue behind two tools, `search_tools` and `execute_tools`, for a client that should not load
 * every tool definition into its context.
 */
final class OrbitSearchServer extends OrbitServer
{
    #[\Override]
    protected string $name = 'Orbit Gateway (search)';

    /** @return array<int|string, mixed> */
    #[\Override]
    protected function catalogue(): array
    {
        $search = new ToolSearch(parent::catalogue());
        [$searchTools] = $search->tools();
        $configuredLimit = config('mcp.tool_search.max_tool_calls', 25);
        $maxToolCalls = is_int($configuredLimit) ? max(1, $configuredLimit) : 25;
        $executeTools = new ExtensionAwareExecuteTools($search, $maxToolCalls);

        return [$searchTools, $executeTools];
    }
}
