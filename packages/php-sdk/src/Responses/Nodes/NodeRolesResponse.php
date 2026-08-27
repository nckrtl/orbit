<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

final readonly class NodeRolesResponse
{
    /** @param list<NodeRoleAssignmentResponse> $assignments */
    public function __construct(
        public array $assignments,
        public string $requestId,
    ) {}

    /**
     * @return array{
     *     assignments: list<array{id: int, role: string, status: string, failed_step: ?string, error_code: ?string}>,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'assignments' => array_map(
                static fn (NodeRoleAssignmentResponse $assignment): array => $assignment->toArray(),
                $this->assignments,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
