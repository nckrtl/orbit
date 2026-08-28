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
final readonly class ToolResponse
{
    private const int MAX_TEXT_LENGTH = 512;
    private const int MAX_TOKEN_LENGTH = 128;

    public function __construct(
        public int $id,
        public int $nodeId,
        public string $manager,
        public string $package,
        public ?string $versionConstraint,
        public bool $protected,
        public string $status,
        public ?string $installedVersion,
        public ?string $failedOperation,
        public ?string $errorCode,
        public ?string $outcome,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $errorCode = GatewayErrorCode::fromTransport($data['error_code'] ?? null);

        if (($data['error_code'] ?? null) !== null && $errorCode === null) {
            throw new UnexpectedValueException('Gateway Tool response contains malformed data.');
        }

        return new self(
            id: self::integer($data, 'id'),
            nodeId: self::integer($data, 'node_id'),
            manager: self::text($data, 'manager', self::MAX_TOKEN_LENGTH),
            package: self::text($data, 'package', self::MAX_TEXT_LENGTH),
            versionConstraint: self::nullableText($data, 'version_constraint', self::MAX_TEXT_LENGTH),
            protected: self::boolean($data, 'protected'),
            status: self::text($data, 'status', self::MAX_TOKEN_LENGTH),
            installedVersion: self::nullableText($data, 'installed_version', self::MAX_TEXT_LENGTH),
            failedOperation: self::nullableText($data, 'failed_operation', self::MAX_TOKEN_LENGTH),
            errorCode: $errorCode,
            outcome: self::nullableText($data, 'outcome', self::MAX_TOKEN_LENGTH),
            requestId: self::requestId($requestId),
        );
    }

    /** @return array<string, int|string|bool|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'node_id' => $this->nodeId,
            'manager' => $this->manager,
            'package' => $this->package,
            'version_constraint' => $this->versionConstraint,
            'protected' => $this->protected,
            'status' => $this->status,
            'installed_version' => $this->installedVersion,
            'failed_operation' => $this->failedOperation,
            'error_code' => $this->errorCode,
            'outcome' => $this->outcome,
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway scalar fields remain mixed until validation.
     * @param array<string, mixed> $data
     */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            throw new UnexpectedValueException('Gateway Tool response contains malformed data.');
        }

        return $value;
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway scalar fields remain mixed until validation.
     * @param array<string, mixed> $data
     */
    private static function boolean(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw new UnexpectedValueException('Gateway Tool response contains malformed data.');
        }

        return $value;
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway scalar fields remain mixed until validation.
     * @param array<string, mixed> $data
     */
    private static function text(array $data, string $key, int $maxLength): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw new UnexpectedValueException('Gateway Tool response contains malformed data.');
        }

        return new CredentialRedactor()->redactText($value);
    }

    /** @param array<string, mixed> $data */
    private static function nullableText(array $data, string $key, int $maxLength): ?string
    {
        if (($data[$key] ?? null) === null) {
            return null;
        }

        return self::text($data, $key, $maxLength);
    }

    private static function requestId(string $requestId): string
    {
        return GatewayRequestId::fromTransport($requestId) ?? '';
    }
}
