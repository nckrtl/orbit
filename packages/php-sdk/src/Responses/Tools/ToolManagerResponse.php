<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayErrorCode;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;
use UnexpectedValueException;

/**
 * @mago-expect lint:cyclomatic-complexity Gateway fields are validated at the DTO boundary.
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class ToolManagerResponse
{
    private const int MAX_LENGTH = 512;

    public function __construct(
        public int $id,
        public int $nodeId,
        public string $name,
        public string $status,
        public ?string $installedVersion,
        public ?string $failedStep,
        public ?string $errorCode,
        public string $requestId,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment Gateway scalar fields remain mixed until validation.
     * @param array<string, mixed> $data
     */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $id = $data['id'] ?? null;
        $nodeId = $data['node_id'] ?? null;
        $errorCode = GatewayErrorCode::fromTransport($data['error_code'] ?? null);

        if (! is_int($id) || $id < 1 || ! is_int($nodeId) || $nodeId < 1) {
            throw new UnexpectedValueException('Gateway Tool manager response contains malformed data.');
        }

        if (($data['error_code'] ?? null) !== null && $errorCode === null) {
            throw new UnexpectedValueException('Gateway Tool manager response contains malformed data.');
        }

        return new self(
            id: $id,
            nodeId: $nodeId,
            name: self::text($data, 'name'),
            status: self::text($data, 'status'),
            installedVersion: self::nullableText($data, 'installed_version'),
            failedStep: self::nullableText($data, 'failed_step'),
            errorCode: $errorCode,
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
        );
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'node_id' => $this->nodeId,
            'name' => $this->name,
            'status' => $this->status,
            'installed_version' => $this->installedVersion,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway scalar fields remain mixed until validation.
     * @param array<string, mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH) {
            throw new UnexpectedValueException('Gateway Tool manager response contains malformed data.');
        }

        return new CredentialRedactor()->redactText($value);
    }

    /** @param array<string, mixed> $data */
    private static function nullableText(array $data, string $key): ?string
    {
        if (($data[$key] ?? null) === null) {
            return null;
        }

        return self::text($data, $key);
    }
}
