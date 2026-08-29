<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayErrorCode;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity Gateway Tool fields are validated at one DTO boundary.
 * @mago-expect lint:too-many-methods DTO validation remains at the transport boundary.
 * @mago-expect lint:too-many-properties DTO mirrors the Gateway response contract.
 */
final readonly class ToolResponse
{
    public int $id;
    public int $nodeId;
    public string $manager;
    public string $package;
    public ?string $versionConstraint;
    public string $status;
    public ?string $installedVersion;
    public ?string $failedOperation;
    public ?string $errorCode;
    public ?string $outcome;
    public string $requestId;

    private const int MANAGER_MAX_LENGTH = 32;

    private const int TEXT_MAX_LENGTH = 255;

    private const int TOKEN_MAX_LENGTH = 32;

    private const string TOKEN_PATTERN = '/\A[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*\z/D';

    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        int $id,
        int $nodeId,
        #[SensitiveParameter]
        string $manager,
        #[SensitiveParameter]
        string $package,
        #[SensitiveParameter]
        ?string $versionConstraint,
        public bool $protected,
        #[SensitiveParameter]
        string $status,
        #[SensitiveParameter]
        ?string $installedVersion,
        #[SensitiveParameter]
        ?string $failedOperation,
        #[SensitiveParameter]
        ?string $errorCode,
        #[SensitiveParameter]
        ?string $outcome,
        #[SensitiveParameter]
        string $requestId,
    ) {
        if ($id < 1 || $nodeId < 1) {
            throw new InvalidArgumentException('Invalid Tool response identifier.');
        }

        $this->id = $id;
        $this->nodeId = $nodeId;
        $this->manager = self::directText($manager, self::MANAGER_MAX_LENGTH, 'manager');
        $this->package = self::directText($package, self::TEXT_MAX_LENGTH, 'package');
        $this->versionConstraint = self::directNullableText(
            $versionConstraint,
            self::TEXT_MAX_LENGTH,
            'version_constraint',
        );
        $this->status = self::directToken($status, 'status');
        $this->installedVersion = self::directNullableText(
            $installedVersion,
            self::TEXT_MAX_LENGTH,
            'installed_version',
        );
        $this->failedOperation = self::directNullableToken($failedOperation, 'failed_operation');
        $this->errorCode = GatewayErrorCode::fromTransport($errorCode);
        $this->outcome = self::directNullableToken($outcome, 'outcome');
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? '';
    }

    private static function directText(#[SensitiveParameter] string $value, int $max, string $key): string
    {
        if ($value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException("Invalid Tool response field [{$key}].");
        }

        return new CredentialRedactor()->redactText($value);
    }

    private static function directNullableText(
        #[SensitiveParameter]
        ?string $value,
        int $max,
        string $key,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (strlen($value) > $max) {
            throw new InvalidArgumentException("Invalid Tool response field [{$key}].");
        }

        return new CredentialRedactor()->redactText($value);
    }

    private static function directToken(#[SensitiveParameter] string $value, string $key): string
    {
        if (
            $value === ''
            || strlen($value) > self::TOKEN_MAX_LENGTH
            || preg_match(self::TOKEN_PATTERN, $value) !== 1
        ) {
            throw new InvalidArgumentException("Invalid Tool response field [{$key}].");
        }

        return new CredentialRedactor()->redactText($value);
    }

    private static function directNullableToken(#[SensitiveParameter] ?string $value, string $key): ?string
    {
        return $value === null ? null : self::directToken($value, $key);
    }

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            id: self::positiveInteger($data, 'id'),
            nodeId: self::positiveInteger($data, 'node_id'),
            manager: self::requiredText($data, 'manager', self::MANAGER_MAX_LENGTH),
            package: self::requiredText($data, 'package', self::TEXT_MAX_LENGTH),
            versionConstraint: self::nullableText($data, 'version_constraint', self::TEXT_MAX_LENGTH),
            protected: self::requiredBoolean($data, 'protected'),
            status: self::requiredToken($data, 'status'),
            installedVersion: self::nullableText($data, 'installed_version', self::TEXT_MAX_LENGTH),
            failedOperation: self::nullableToken($data, 'failed_operation'),
            errorCode: GatewayErrorCode::fromTransport($data['error_code'] ?? null),
            outcome: self::nullableToken($data, 'outcome'),
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
        );
    }

    /** @return array<string, bool|int|string|null> */
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

    /** @param array<string, mixed> $data */
    private static function positiveInteger(#[SensitiveParameter] array $data, string $key): int
    {
        if (! is_int($data[$key] ?? null) || $data[$key] < 1) {
            throw new InvalidArgumentException("Invalid Tool response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function requiredBoolean(#[SensitiveParameter] array $data, string $key): bool
    {
        if (! is_bool($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid Tool response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function requiredText(
        #[SensitiveParameter]
        array $data,
        string $key,
        int $maxLength,
    ): string {
        if (
            ! is_string($data[$key] ?? null)
            || $data[$key] === ''
            || strlen($data[$key]) > $maxLength
        ) {
            throw new InvalidArgumentException("Invalid Tool response field [{$key}].");
        }

        return new CredentialRedactor()->redactText($data[$key]);
    }

    /** @param array<string, mixed> $data */
    private static function nullableText(
        #[SensitiveParameter]
        array $data,
        string $key,
        int $maxLength,
    ): ?string {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (! is_string($data[$key]) || strlen($data[$key]) > $maxLength) {
            throw new InvalidArgumentException("Invalid Tool response field [{$key}].");
        }

        return new CredentialRedactor()->redactText($data[$key]);
    }

    /** @param array<string, mixed> $data */
    private static function requiredToken(#[SensitiveParameter] array $data, string $key): string
    {
        $value = self::requiredText($data, $key, self::TOKEN_MAX_LENGTH);

        if (preg_match(self::TOKEN_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Invalid Tool response field [{$key}].");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function nullableToken(#[SensitiveParameter] array $data, string $key): ?string
    {
        $value = self::nullableText($data, $key, self::TOKEN_MAX_LENGTH);

        if ($value !== null && preg_match(self::TOKEN_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Invalid Tool response field [{$key}].");
        }

        return $value;
    }
}
