<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Processes;

final readonly class ProcessesResponse
{
    /** @param list<ProcessResponse> $processes */
    public function __construct(
        public array $processes,
        public string $requestId,
    ) {}

    /** @return array{processes: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'processes' => array_map(
                static function (ProcessResponse $process): array {
                    $data = $process->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->processes,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
