<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity Mutation transport rejects each invalid field and state explicitly.
 * @mago-expect lint:excessive-parameter-list Stable mutation DTO fields are part of the public contract.
 */
final readonly class NodeRoleMutationResponse
{
    private const int MAX_NODE_NAME_LENGTH = 63;

    public function __construct(
        public int $nodeId,
        public string $nodeName,
        public string $role,
        public ?NodeRoleAssignmentResponse $assignment,
        public bool $removed,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        /** @mago-expect analysis:mixed-assignment Gateway mutation data is an untyped transport boundary. */
        $nodeId = $data['node_id'] ?? null;

        if (! is_int($nodeId) || $nodeId < 1) {
            throw new GatewayApiException(
                'Gateway response contains an invalid node role mutation node_id.',
                requestId: $requestId,
            );
        }

        /** @mago-expect analysis:mixed-assignment Gateway mutation data is an untyped transport boundary. */
        $nodeName = $data['node_name'] ?? null;

        if (! self::isSafeNodeName($nodeName)) {
            throw new GatewayApiException(
                'Gateway response contains an invalid node role mutation node_name.',
                requestId: $requestId,
            );
        }

        /** @var string $nodeName */

        /** @mago-expect analysis:mixed-assignment Gateway mutation data is an untyped transport boundary. */
        $role = $data['role'] ?? null;

        if (! self::isSafeRole($role)) {
            throw new GatewayApiException(
                'Gateway response contains an invalid node role mutation role.',
                requestId: $requestId,
            );
        }

        /** @mago-expect analysis:mixed-assignment Gateway mutation data is an untyped transport boundary. */
        $removed = $data['removed'] ?? null;

        if (! is_bool($removed)) {
            throw new GatewayApiException(
                'Gateway response contains an invalid node role mutation removed flag.',
                requestId: $requestId,
            );
        }

        /** @mago-expect analysis:mixed-assignment Gateway mutation data is an untyped transport boundary. */
        $assignmentData = $data['assignment'] ?? null;
        $assignment = null;

        if (is_array($assignmentData)) {
            $assignment = NodeRoleAssignmentResponse::fromGatewayData(
                self::stringKeyedArray($assignmentData),
                $requestId,
            );
        }

        if (! is_array($assignmentData) && $assignmentData !== null) {
            throw new GatewayApiException(
                'Gateway response contains an invalid node role mutation assignment.',
                requestId: $requestId,
            );
        }

        if ($removed && $assignment !== null || ! $removed && $assignment === null) {
            throw new GatewayApiException(
                'Gateway response contains an invalid node role mutation state.',
                requestId: $requestId,
            );
        }

        /** @var string $role */
        return new self(
            nodeId: $nodeId,
            nodeName: $nodeName,
            role: $role,
            assignment: $assignment,
            removed: $removed,
            requestId: $requestId,
        );
    }

    /**
     * @return array{
     *     node_id: int,
     *     node_name: string,
     *     role: string,
     *     assignment: array{id: int, role: string, status: string, failed_step: ?string, error_code: ?string}|null,
     *     removed: bool,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'node_name' => $this->nodeName,
            'role' => $this->role,
            'assignment' => $this->assignment?->toArray(),
            'removed' => $this->removed,
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(array $value): array
    {
        /** @var array<string, mixed> $result */
        $result = [];

        /** @mago-expect analysis:mixed-assignment Gateway mutation payload values stay mixed until copied into a keyed array. */
        foreach ($value as $key => $item) {
            /** @var mixed $item */
            if (! is_string($key)) {
                continue;
            }

            $result[$key] = $item;
        }

        return $result;
    }

    private static function isSafeRole(mixed $value): bool
    {
        return (
            is_string($value)
            && $value !== ''
            && strlen($value) <= 128
            && preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/D', $value) === 1
        );
    }

    private static function isSafeNodeName(mixed $value): bool
    {
        return (
            is_string($value)
            && $value !== ''
            && strlen($value) <= self::MAX_NODE_NAME_LENGTH
            && preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) === 1
        );
    }
}
