<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Schedules;

final readonly class NodeScheduleTarget implements ScheduleTarget
{
    public function __construct(public int $nodeId) {}

    /** @return array{target_type: string, target_id: int} */
    public function toRequestData(): array
    {
        return ['target_type' => 'node', 'target_id' => $this->nodeId];
    }
}
