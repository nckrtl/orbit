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
        return [ToolSearch::class => parent::catalogue()];
    }
}
