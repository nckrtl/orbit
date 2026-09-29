<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Projects;

final readonly class ProjectsResponse
{
    /** @param list<ProjectResponse> $projects */
    public function __construct(
        public array $projects,
        public string $requestId,
    ) {}

    /** @return array{projects: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'projects' => array_map(
                static function (ProjectResponse $project): array {
                    $data = $project->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->projects,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
