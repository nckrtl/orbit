<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TaskDefinitionsResponse
{
    /** @param list<TaskDefinitionResponse> $definitions */
    public function __construct(
        public array $definitions,
        public string $requestId,
    ) {}

    /** @return array{task_definitions: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'task_definitions' => array_map(static function (TaskDefinitionResponse $definition): array {
                $data = $definition->toArray();
                unset($data['request_id']);

                return $data;
            }, $this->definitions),
            'request_id' => $this->requestId,
        ];
    }
}
