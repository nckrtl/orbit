<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\AppDev\AgentationEndpoint;

final readonly class AgentationMcpPreset
{
    public const string NAME = 'agentation-mcp';

    /** @return list<string> */
    public static function command(): array
    {
        return ['/usr/local/bin/agentation-mcp', 'server', '--port=${'.AgentationEndpoint::PORT_KEY.'}'];
    }
}
