<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Processes;

final readonly class ProcessLogsResponse
{
    public function __construct(
        public int $id,
        public string $name,
        public int $lines,
        public string $logs,
        public string $requestId,
    ) {}

    /** @return array{id: int, name: string, lines: int, logs: string, request_id: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'lines' => $this->lines,
            'logs' => $this->logs,
            'request_id' => $this->requestId,
        ];
    }
}
