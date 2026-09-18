<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseConnections;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class DatabaseConnectionResponse
{
    private const int SLUG_MAX_LENGTH = 63;

    private const int DRIVER_MAX_LENGTH = 16;

    private const int HOST_MAX_LENGTH = 255;

    private const int DATABASE_MAX_LENGTH = 64;

    private const int PATH_MAX_LENGTH = 1024;

    private const int USERNAME_MAX_LENGTH = 128;

    private const string SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    private const string DRIVER_PATTERN = '/\A(?:mysql|pgsql|sqlite|redis)\z/D';

    public function __construct(
        public int $id,
        public string $slug,
        public string $driver,
        public ?int $nodeId,
        public ?string $host,
        public ?int $port,
        public ?string $database,
        public ?string $path,
        public ?string $username,
        public bool $hasPassword,
        public string $requestId,
        public ?int $usersCount = null,
    ) {
        if ($id < 1 || ($nodeId !== null && $nodeId < 1)) {
            throw new InvalidArgumentException('Invalid Database connection response identifier.');
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('Invalid Database connection response field [port].');
        }

        if ($usersCount !== null && $usersCount < 0) {
            throw new InvalidArgumentException('Invalid Database connection response field [users_count].');
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
            id: self::requiredInteger($data, 'id'),
            slug: self::requiredPattern($data, 'slug', self::SLUG_PATTERN, self::SLUG_MAX_LENGTH),
            driver: self::requiredPattern($data, 'driver', self::DRIVER_PATTERN, self::DRIVER_MAX_LENGTH),
            nodeId: self::nullableInteger($data, 'node_id'),
            host: self::nullableText($data, 'host', self::HOST_MAX_LENGTH, $redactor),
            port: self::nullableInteger($data, 'port'),
            database: self::nullableText($data, 'database', self::DATABASE_MAX_LENGTH, $redactor),
            path: self::nullableText($data, 'path', self::PATH_MAX_LENGTH, $redactor),
            username: self::nullableText($data, 'username', self::USERNAME_MAX_LENGTH, $redactor),
            hasPassword: self::requiredBoolean($data, 'has_password'),
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
            usersCount: self::nullableInteger($data, 'users_count'),
        );
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'slug' => $this->slug,
            'driver' => $this->driver,
            'node_id' => $this->nodeId,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'path' => $this->path,
            'username' => $this->username,
            'has_password' => $this->hasPassword,
            'request_id' => $this->requestId,
        ];

        if ($this->usersCount !== null) {
            $data['users_count'] = $this->usersCount;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private static function requiredInteger(#[SensitiveParameter] array $data, string $key): int
    {
        if (! is_int($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid Database connection response field [{$key}].");
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
            throw new InvalidArgumentException("Invalid Database connection response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function requiredBoolean(#[SensitiveParameter] array $data, string $key): bool
    {
        if (! is_bool($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid Database connection response field [{$key}].");
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
            throw new InvalidArgumentException("Invalid Database connection response field [{$key}].");
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
            throw new InvalidArgumentException("Invalid Database connection response field [{$key}].");
        }

        return $redactor->redactText($data[$key]);
    }
}
