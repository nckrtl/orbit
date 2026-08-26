<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class NodeRoleResponse
{
    public function __construct(
        public int $nodeId,
        public string $nodeName,
        public NodeRoleAssignmentResponse $assignment,
        public string $requestId,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment Gateway role result values remain mixed until validated.
     *
     * @param array<string, mixed> $data
     */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $nodeId = $data['node_id'] ?? null;
        $nodeName = $data['node_name'] ?? null;
        $assignmentData = $data['assignment'] ?? null;

        if (
            ! is_int($nodeId)
            || $nodeId < 1
            || ! is_string($nodeName)
            || ! self::isSafeNodeName($nodeName)
            || ! is_array($assignmentData)
        ) {
            self::invalid();
        }

        $assignment = NodeRoleAssignmentResponse::fromGatewayData($assignmentData);

        if ($assignment->role !== 'app-dev') {
            self::invalid();
        }

        return new self($nodeId, $nodeName, $assignment, $requestId);
    }

    /** @return array{node_id: int, node_name: string, assignment: array{role: string, status: string, failed_step: ?string, error_code: ?string, local_action_required: bool, local_command: ?string}, request_id: string} */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'node_name' => $this->nodeName,
            'assignment' => $this->assignment->toArray(),
            'request_id' => $this->requestId,
        ];
    }

    private static function isSafeNodeName(#[SensitiveParameter] string $name): bool
    {
        return $name !== '' && strlen($name) <= 255 && preg_match('/[\x00-\x1F\x7F]/', $name) !== 1;
    }

    private static function invalid(): never
    {
        throw new GatewayApiException('Gateway response contains an invalid node role result.');
    }
}
