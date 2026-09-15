<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseConnections;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class DatabaseSchemaResponse
{
    /**
     * @param  list<array{name: string, columns: list<array{name: string, type: string, nullable: bool, default: string|null, primary: bool}>}>  $tables
     */
    public function __construct(
        public string $slug,
        public string $driver,
        public array $tables,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            slug: self::requiredString($data, 'slug'),
            driver: self::requiredString($data, 'driver'),
            tables: DatabaseDescribeResponse::tablesFromGateway($data['tables'] ?? null),
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'driver' => $this->driver,
            'tables' => $this->tables,
            'request_id' => $this->requestId,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(#[SensitiveParameter] array $data, string $key): string
    {
        if (! is_string($data[$key] ?? null) || $data[$key] === '') {
            throw new InvalidArgumentException("Invalid Database schema response field [{$key}].");
        }

        return $data[$key];
    }
}
