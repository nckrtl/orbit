<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseConnections;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class DatabaseConnectionAttachmentResponse
{
    private const int SLUG_MAX_LENGTH = 63;

    private const int PREFIX_MAX_LENGTH = 32;

    private const int HOST_MAX_LENGTH = 255;

    private const int MAX_KEY_COUNT = 1_024;

    private const string SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    private const string PREFIX_PATTERN = '/\A[A-Z][A-Z0-9_]{0,31}\z/D';

    private const string OPERATION_PATTERN = '/\A(?:attach|detach)\z/D';

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public int $appInstanceId,
        public string $slug,
        public string $prefix,
        public array $keys,
        public ?string $host,
        public ?int $port,
        public string $operation,
        public bool $changed,
        public int $keyCount,
        public string $requestId,
    ) {
        if ($appInstanceId < 1) {
            throw new InvalidArgumentException('Invalid Database connection attachment response identifier.');
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('Invalid Database connection attachment response field [port].');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $redactor = new CredentialRedactor;

        return new self(
            appInstanceId: self::requiredInteger($data, 'app_instance_id'),
            slug: self::requiredPattern($data, 'slug', self::SLUG_PATTERN, self::SLUG_MAX_LENGTH),
            prefix: self::requiredPattern($data, 'prefix', self::PREFIX_PATTERN, self::PREFIX_MAX_LENGTH),
            keys: self::requiredKeys($data),
            host: self::nullableText($data, 'host', self::HOST_MAX_LENGTH, $redactor),
            port: self::nullableInteger($data, 'port'),
            operation: self::requiredPattern($data, 'operation', self::OPERATION_PATTERN, 16),
            changed: self::requiredBoolean($data, 'changed'),
            keyCount: self::requiredInteger($data, 'key_count'),
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
        );
    }

    /** @return array<string, array<int, string>|bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'app_instance_id' => $this->appInstanceId,
            'slug' => $this->slug,
            'prefix' => $this->prefix,
            'keys' => $this->keys,
            'host' => $this->host,
            'port' => $this->port,
            'operation' => $this->operation,
            'changed' => $this->changed,
            'key_count' => $this->keyCount,
            'request_id' => $this->requestId,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function requiredInteger(#[SensitiveParameter] array $data, string $key): int
    {
        if (! is_int($data[$key] ?? null) || ($key === 'key_count' && ($data[$key] < 0 || $data[$key] > self::MAX_KEY_COUNT))) {
            throw new InvalidArgumentException("Invalid Database connection attachment response field [{$key}].");
        }

        if ($key !== 'key_count' && $data[$key] < 1) {
            throw new InvalidArgumentException("Invalid Database connection attachment response field [{$key}].");
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
            throw new InvalidArgumentException("Invalid Database connection attachment response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function requiredBoolean(#[SensitiveParameter] array $data, string $key): bool
    {
        if (! is_bool($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid Database connection attachment response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function requiredPattern(
        #[SensitiveParameter]
        array $data,
        string $key,
        string $pattern,
        int $max,
    ): string {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '' || strlen($value) > $max || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException("Invalid Database connection attachment response field [{$key}].");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function nullableText(
        #[SensitiveParameter]
        array $data,
        string $key,
        int $max,
        CredentialRedactor $redactor,
    ): ?string {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (! is_string($data[$key]) || strlen($data[$key]) > $max) {
            throw new InvalidArgumentException("Invalid Database connection attachment response field [{$key}].");
        }

        return $redactor->redactText($data[$key]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function requiredKeys(#[SensitiveParameter] array $data): array
    {
        $keys = $data['keys'] ?? null;

        if (! is_array($keys) || ! array_is_list($keys)) {
            throw new InvalidArgumentException('Invalid Database connection attachment response field [keys].');
        }

        foreach ($keys as $key) {
            if (! is_string($key) || $key === '' || preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,254}\z/D', $key) !== 1) {
                throw new InvalidArgumentException('Invalid Database connection attachment response field [keys].');
            }
        }

        return $keys;
    }
}
