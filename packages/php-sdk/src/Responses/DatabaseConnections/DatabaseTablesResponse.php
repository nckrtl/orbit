<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseConnections;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class DatabaseTablesResponse
{
    /** @param list<string> $tables */
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
        $redactor = new CredentialRedactor;
        $tables = [];

        foreach ($data['tables'] ?? [] as $table) {
            if (! is_string($table) || $table === '') {
                throw new InvalidArgumentException('Invalid Database tables response field [tables].');
            }

            $tables[] = $redactor->redactText($table);
        }

        return new self(
            slug: self::requiredString($data, 'slug'),
            driver: self::requiredString($data, 'driver'),
            tables: $tables,
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
            throw new InvalidArgumentException("Invalid Database tables response field [{$key}].");
        }

        return $data[$key];
    }
}
