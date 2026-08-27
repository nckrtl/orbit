<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Processes;

use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayErrorCode;
use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity Gateway values are validated at the DTO boundary.
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class ProcessResponse
{
    /** @param array<string, mixed> $runtimeConfig */
    public function __construct(
        public int $id,
        public string $targetType,
        public int $targetId,
        public string $name,
        public string $runtime,
        public string $workingDirectory,
        public array $runtimeConfig,
        public string $restartPolicy,
        public string $desiredState,
        public string $status,
        public string $runtimeStatus,
        public ?string $failedStep,
        public ?string $errorCode,
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
            targetType: is_string($data['target_type'] ?? null) ? $data['target_type'] : '',
            targetId: is_int($data['target_id'] ?? null) ? $data['target_id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            runtime: is_string($data['runtime'] ?? null) ? $data['runtime'] : '',
            workingDirectory: is_string($data['working_directory'] ?? null)
                ? $data['working_directory']
                : '',
            runtimeConfig: $redactor->redactArray(self::stringKeyedArray($data['runtime_config'] ?? null)),
            restartPolicy: is_string($data['restart_policy'] ?? null) ? $data['restart_policy'] : '',
            desiredState: is_string($data['desired_state'] ?? null) ? $data['desired_state'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            runtimeStatus: is_string($data['runtime_status'] ?? null) ? $data['runtime_status'] : '',
            failedStep: is_string($data['failed_step'] ?? null) ? $data['failed_step'] : null,
            errorCode: GatewayErrorCode::fromTransport($data['error_code'] ?? null),
            requestId: $requestId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'name' => $this->name,
            'runtime' => $this->runtime,
            'working_directory' => $this->workingDirectory,
            'runtime_config' => $this->runtimeConfig,
            'restart_policy' => $this->restartPolicy,
            'desired_state' => $this->desiredState,
            'status' => $this->status,
            'runtime_status' => $this->runtimeStatus,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway runtime values remain mixed after key validation.
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
