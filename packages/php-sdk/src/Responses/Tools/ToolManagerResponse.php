<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayErrorCode;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity DTO validation remains at the transport boundary.
 * @mago-expect lint:too-many-methods DTO validation remains at the transport boundary.
 */
final readonly class ToolManagerResponse
{
    public ?int $id;
    public int $nodeId;
    public string $name;
    public string $status;
    public ?string $installedVersion;
    public ?string $failedStep;
    public ?string $errorCode;
    public string $requestId;

    private const int NAME_MAX_LENGTH = 32;

    private const int TEXT_MAX_LENGTH = 255;

    private const int TOKEN_MAX_LENGTH = 32;

    private const string TOKEN_PATTERN = '/\A[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*\z/D';

    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        ?int $id,
        int $nodeId,
        #[SensitiveParameter]
        string $name,
        #[SensitiveParameter]
        string $status,
        #[SensitiveParameter]
        ?string $installedVersion,
        #[SensitiveParameter]
        ?string $failedStep,
        #[SensitiveParameter]
        ?string $errorCode,
        #[SensitiveParameter]
        string $requestId,
    ) {
        if ($id !== null && $id < 1 || $nodeId < 1) {
            throw new InvalidArgumentException('Invalid Tool manager response identifier.');
        }

        $this->id = $id;
        $this->nodeId = $nodeId;
        $this->name = self::directText($name, self::NAME_MAX_LENGTH, 'name');
        $this->status = self::directToken($status, 'status');
        $this->installedVersion = self::directNullableText($installedVersion, 'installed_version');
        $this->failedStep = self::directNullableText($failedStep, 'failed_step');
        $this->errorCode = GatewayErrorCode::fromTransport($errorCode);
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? '';
    }

    private static function directNullableText(#[SensitiveParameter] ?string $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        if (strlen($value) > self::TEXT_MAX_LENGTH) {
            throw new InvalidArgumentException("Invalid Tool manager response field [{$key}].");
        }

        return new CredentialRedactor()->redactText($value);
    }

    private static function directText(#[SensitiveParameter] string $value, int $max, string $key): string
    {
        if ($value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException("Invalid Tool manager response field [{$key}].");
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
            throw new InvalidArgumentException("Invalid Tool manager response field [{$key}].");
        }

        return new CredentialRedactor()->redactText($value);
    }

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            id: self::nullableInteger($data, 'id'),
            nodeId: self::requiredInteger($data, 'node_id'),
            name: self::requiredString($data, 'name'),
            status: self::requiredString($data, 'status'),
            installedVersion: self::nullableString($data, 'installed_version'),
            failedStep: self::nullableString($data, 'failed_step'),
            errorCode: self::stringOrNull($data, 'error_code'),
            requestId: $requestId,
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

    /** @param array<string, mixed> $data */
    private static function requiredInteger(#[SensitiveParameter] array $data, string $key): int
    {
        if (! is_int($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid Tool manager response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function nullableInteger(#[SensitiveParameter] array $data, string $key): ?int
    {
        if (($data[$key] ?? null) === null) {
            return null;
        }

        return self::requiredInteger($data, $key);
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(#[SensitiveParameter] array $data, string $key): string
    {
        if (! is_string($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid Tool manager response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function nullableString(#[SensitiveParameter] array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (! is_string($data[$key])) {
            throw new InvalidArgumentException("Invalid Tool manager response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function stringOrNull(#[SensitiveParameter] array $data, string $key): ?string
    {
        /** @mago-expect analysis:mixed-assignment Gateway Tool manager fields are decoded from mixed transport data. */
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
