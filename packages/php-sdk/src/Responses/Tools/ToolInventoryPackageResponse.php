<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class ToolInventoryPackageResponse
{
    public string $manager;

    public string $package;

    public string $packageKind;

    public ?string $installedVersion;

    public ?int $toolId;

    public string $adoption;

    public ?string $adoptionBlock;

    public string $requestId;

    private const int MANAGER_MAX_LENGTH = 32;

    private const int TEXT_MAX_LENGTH = 255;

    private const int TOKEN_MAX_LENGTH = 32;

    private const string TOKEN_PATTERN = '/\A[a-z][a-z0-9]*(?:[_-][a-z0-9]+)*\z/D';

    public function __construct(
        #[SensitiveParameter]
        string $manager,
        #[SensitiveParameter]
        string $package,
        #[SensitiveParameter]
        string $packageKind,
        #[SensitiveParameter]
        ?string $installedVersion,
        public bool $dependency,
        public bool $registered,
        ?int $toolId,
        #[SensitiveParameter]
        string $adoption,
        #[SensitiveParameter]
        ?string $adoptionBlock,
        #[SensitiveParameter]
        string $requestId,
    ) {
        if ($toolId !== null && $toolId < 1) {
            throw new InvalidArgumentException('Invalid Tool inventory response identifier.');
        }

        $this->manager = self::directToken($manager, self::MANAGER_MAX_LENGTH, 'manager');
        $this->package = self::directText($package, 'package');
        $this->packageKind = self::directToken($packageKind, self::TOKEN_MAX_LENGTH, 'package_kind');
        $this->installedVersion = self::directNullableText($installedVersion, 'installed_version');
        $this->toolId = $toolId;
        $this->adoption = self::directToken($adoption, self::TOKEN_MAX_LENGTH, 'adoption');
        $this->adoptionBlock = self::directNullableToken($adoptionBlock, 'adoption_block');
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? '';
    }

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            manager: self::requiredString($data, 'manager'),
            package: self::requiredString($data, 'package'),
            packageKind: self::requiredString($data, 'package_kind'),
            installedVersion: self::nullableString($data, 'installed_version'),
            dependency: self::requiredBool($data, 'dependency'),
            registered: self::requiredBool($data, 'registered'),
            toolId: self::nullableInteger($data, 'tool_id'),
            adoption: self::requiredString($data, 'adoption'),
            adoptionBlock: self::nullableString($data, 'adoption_block'),
            requestId: $requestId,
        );
    }

    /**
     * @return array{
     *     manager: string,
     *     package: string,
     *     package_kind: string,
     *     installed_version: string|null,
     *     dependency: bool,
     *     registered: bool,
     *     tool_id: int|null,
     *     adoption: string,
     *     adoption_block: string|null,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'manager' => $this->manager,
            'package' => $this->package,
            'package_kind' => $this->packageKind,
            'installed_version' => $this->installedVersion,
            'dependency' => $this->dependency,
            'registered' => $this->registered,
            'tool_id' => $this->toolId,
            'adoption' => $this->adoption,
            'adoption_block' => $this->adoptionBlock,
            'request_id' => $this->requestId,
        ];
    }

    private static function directText(#[SensitiveParameter] string $value, string $key): string
    {
        if ($value === '' || strlen($value) > self::TEXT_MAX_LENGTH) {
            throw new InvalidArgumentException("Invalid Tool inventory response field [{$key}].");
        }

        return new CredentialRedactor()->redactText($value);
    }

    private static function directNullableText(#[SensitiveParameter] ?string $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        if (strlen($value) > self::TEXT_MAX_LENGTH) {
            throw new InvalidArgumentException("Invalid Tool inventory response field [{$key}].");
        }

        return new CredentialRedactor()->redactText($value);
    }

    private static function directToken(#[SensitiveParameter] string $value, int $max, string $key): string
    {
        if ($value === '' || strlen($value) > $max || preg_match(self::TOKEN_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Invalid Tool inventory response field [{$key}].");
        }

        return new CredentialRedactor()->redactText($value);
    }

    private static function directNullableToken(#[SensitiveParameter] ?string $value, string $key): ?string
    {
        return $value === null ? null : self::directToken($value, self::TOKEN_MAX_LENGTH, $key);
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(#[SensitiveParameter] array $data, string $key): string
    {
        if (! is_string($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid Tool inventory response field [{$key}].");
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
            throw new InvalidArgumentException("Invalid Tool inventory response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function requiredBool(#[SensitiveParameter] array $data, string $key): bool
    {
        if (! is_bool($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid Tool inventory response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function nullableInteger(#[SensitiveParameter] array $data, string $key): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (! is_int($data[$key])) {
            throw new InvalidArgumentException("Invalid Tool inventory response field [{$key}].");
        }

        return $data[$key];
    }
}
