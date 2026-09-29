<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Projects;

final readonly class ProjectRuntimeDefinitionsResponse
{
    /** @param list<ProjectRuntimeDefinitionResponse> $definitions */
    public function __construct(
        public array $definitions,
        public string $requestId,
    ) {}

    /** @return array{definitions: list<array{id: string, project_id: int, name: string, environments: list<string>, spec: array<string, mixed>}>, request_id: string} */
    public function toArray(): array
    {
        return [
            'definitions' => array_map(
                static function (ProjectRuntimeDefinitionResponse $definition): array {
                    $data = $definition->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->definitions,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
