<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Logs;

final readonly class ProcessLogStreamTarget implements LogStreamTarget
{
    public function __construct(public int $processId) {}

    public function logStreamsPath(): string
    {
        return "/api/v1/processes/{$this->processId}/log-streams";
    }
}
