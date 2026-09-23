<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TaskAgentsResponse
{
    /** @param list<TaskAgentResponse> $agents */
    public function __construct(
        public array $agents,
        public string $requestId,
    ) {}

    /** @return array{agents: list<array<string, int|string|null>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'agents' => array_map(static function (TaskAgentResponse $agent): array {
                $data = $agent->toArray();
                unset($data['request_id']);

                return $data;
            }, $this->agents),
            'request_id' => $this->requestId,
        ];
    }
}
