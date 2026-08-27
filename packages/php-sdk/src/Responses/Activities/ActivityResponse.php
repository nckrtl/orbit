<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Activities;

use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayErrorCode;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity Gateway scalar fields are validated independently.
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class ActivityResponse
{
    /** @param array<string, mixed> $properties */
    public function __construct(
        public int $id,
        public string $activityRequestId,
        public string $command,
        public ?int $callerNodeId,
        public ?int $targetNodeId,
        public ?string $callerIp,
        public string $status,
        public ?int $durationMs,
        public ?int $exitCode,
        public ?string $errorCode,
        public ?string $subjectType,
        public ?int $subjectId,
        public array $properties,
        public string $occurredAt,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $redactor = new CredentialRedactor;

        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            activityRequestId: GatewayRequestId::fromTransport($data['request_id'] ?? null) ?? '',
            command: is_string($data['command'] ?? null) ? $data['command'] : '',
            callerNodeId: is_int($data['caller_node_id'] ?? null) ? $data['caller_node_id'] : null,
            targetNodeId: is_int($data['target_node_id'] ?? null) ? $data['target_node_id'] : null,
            callerIp: is_string($data['caller_ip'] ?? null) ? $data['caller_ip'] : null,
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            durationMs: is_int($data['duration_ms'] ?? null) ? $data['duration_ms'] : null,
            exitCode: is_int($data['exit_code'] ?? null) ? $data['exit_code'] : null,
            errorCode: GatewayErrorCode::fromTransport($data['error_code'] ?? null),
            subjectType: is_string($data['subject_type'] ?? null) ? $data['subject_type'] : null,
            subjectId: is_int($data['subject_id'] ?? null) ? $data['subject_id'] : null,
            properties: $redactor->redactArray(self::stringKeyedArray($data['properties'] ?? null)),
            occurredAt: is_string($data['occurred_at'] ?? null) ? $data['occurred_at'] : '',
            requestId: $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->activityRequestId,
            'command' => $this->command,
            'caller_node_id' => $this->callerNodeId,
            'target_node_id' => $this->targetNodeId,
            'caller_ip' => $this->callerIp,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
            'exit_code' => $this->exitCode,
            'error_code' => $this->errorCode,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'properties' => $this->properties,
            'occurred_at' => $this->occurredAt,
            'gateway_request_id' => $this->requestId,
        ];
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway property values remain mixed after key validation.
     *
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $result[$key] = $item;
        }

        return $result;
    }
}
