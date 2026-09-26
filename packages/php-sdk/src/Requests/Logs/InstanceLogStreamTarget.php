<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Logs;

final readonly class InstanceLogStreamTarget implements LogStreamTarget
{
    public function __construct(public int $instanceId) {}

    public function logStreamsPath(): string
    {
        return "/api/v1/instances/{$this->instanceId}/log-streams";
    }
}
