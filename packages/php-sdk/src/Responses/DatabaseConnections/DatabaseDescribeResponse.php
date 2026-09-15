<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseConnections;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class DatabaseDescribeResponse
{
    /**
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, primary: bool}>  $columns
     */
    public function __construct(
        public string $slug,
        public string $driver,
        public string $table,
        public array $columns,
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
            table: self::requiredString($data, 'table'),
            columns: self::columnsFromGateway($data['columns'] ?? null),
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
        );
    }

    /**
     * @return list<array{name: string, columns: list<array{name: string, type: string, nullable: bool, default: string|null, primary: bool}>}>
     */
    public static function tablesFromGateway(#[SensitiveParameter] mixed $tables): array
    {
        if (! is_array($tables)) {
            throw new InvalidArgumentException('Invalid Database schema response field [tables].');
        }

        $normalized = [];

        foreach ($tables as $table) {
            if (! is_array($table) || ! is_string($table['name'] ?? null) || $table['name'] === '') {
                throw new InvalidArgumentException('Invalid Database schema response field [tables].');
            }

            $normalized[] = [
                'name' => (new CredentialRedactor)->redactText($table['name']),
                'columns' => self::columnsFromGateway($table['columns'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, primary: bool}>
     */
    public static function columnsFromGateway(#[SensitiveParameter] mixed $columns): array
    {
        if (! is_array($columns)) {
            throw new InvalidArgumentException('Invalid Database describe response field [columns].');
        }

        $redactor = new CredentialRedactor;
        $normalized = [];

        foreach ($columns as $column) {
            if (
                ! is_array($column)
                || ! is_string($column['name'] ?? null)
                || $column['name'] === ''
                || ! is_string($column['type'] ?? null)
                || ! is_bool($column['nullable'] ?? null)
                || ! is_bool($column['primary'] ?? null)
                || (array_key_exists('default', $column) && $column['default'] !== null && ! is_string($column['default']))
            ) {
                throw new InvalidArgumentException('Invalid Database describe response field [columns].');
            }

            $normalized[] = [
                'name' => $redactor->redactText($column['name']),
                'type' => $redactor->redactText($column['type']),
                'nullable' => $column['nullable'],
                'default' => is_string($column['default'] ?? null) ? $redactor->redactText($column['default']) : null,
                'primary' => $column['primary'],
            ];
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'driver' => $this->driver,
            'table' => $this->table,
            'columns' => $this->columns,
            'request_id' => $this->requestId,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(#[SensitiveParameter] array $data, string $key): string
    {
        if (! is_string($data[$key] ?? null) || $data[$key] === '') {
            throw new InvalidArgumentException("Invalid Database describe response field [{$key}].");
        }

        return $data[$key];
    }
}
