<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Workspaces;

final readonly class WorkspacesResponse
{
    /** @param list<WorkspaceResponse> $workspaces */
    public function __construct(
        public array $workspaces,
        public string $requestId,
    ) {}

    /** @return array{workspaces: list<array<string, int|string|null>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'workspaces' => array_map(
                static function (WorkspaceResponse $workspace): array {
                    $data = $workspace->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->workspaces,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
