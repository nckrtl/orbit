<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseConnections;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class DatabaseQueryResponse
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<string, bool|float|int|string|null>>  $rows
     */
    public function __construct(
        public string $slug,
        public string $driver,
        public bool $write,
        public array $columns,
        public array $rows,
        public int $rowCount,
        public bool $truncated,
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
        $columns = [];
        $rows = [];

        foreach ($data['columns'] ?? [] as $column) {
            if (! is_string($column) || $column === '') {
                throw new InvalidArgumentException('Invalid Database query response field [columns].');
            }

            $columns[] = $redactor->redactText($column);
        }

        foreach ($data['rows'] ?? [] as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Invalid Database query response field [rows].');
            }

            $normalized = [];

            foreach ($row as $key => $value) {
                $normalized[$redactor->redactText((string) $key)] = is_string($value)
                    ? $redactor->redactText($value)
                    : (is_bool($value) || is_int($value) || is_float($value) || $value === null ? $value : null);
            }

            $rows[] = $normalized;
        }

        return new self(
            slug: self::requiredString($data, 'slug'),
            driver: self::requiredString($data, 'driver'),
            write: is_bool($data['write'] ?? null) ? $data['write'] : throw new InvalidArgumentException('Invalid Database query response field [write].'),
            columns: $columns,
            rows: $rows,
            rowCount: is_int($data['row_count'] ?? null) ? $data['row_count'] : throw new InvalidArgumentException('Invalid Database query response field [row_count].'),
            truncated: is_bool($data['truncated'] ?? null) ? $data['truncated'] : throw new InvalidArgumentException('Invalid Database query response field [truncated].'),
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'driver' => $this->driver,
            'write' => $this->write,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'row_count' => $this->rowCount,
            'truncated' => $this->truncated,
            'request_id' => $this->requestId,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(#[SensitiveParameter] array $data, string $key): string
    {
        if (! is_string($data[$key] ?? null) || $data[$key] === '') {
            throw new InvalidArgumentException("Invalid Database query response field [{$key}].");
        }

        return $data[$key];
    }
}
