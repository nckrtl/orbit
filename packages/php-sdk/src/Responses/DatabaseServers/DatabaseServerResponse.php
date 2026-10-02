<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseServers;

use InvalidArgumentException;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

/** A Database server. The Gateway never returns its root password. */
final readonly class DatabaseServerResponse
{
    private const string SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    private const string TAG_PATTERN = '/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/D';

    private const string STATUS_PATTERN = '/\A(?:provisioning|active|failed|removing)\z/D';

    /**
     * @param  list<DatabaseConnectionResponse>|null  $databases  Present only when the server is shown.
     */
    public function __construct(
        public int $id,
        public string $slug,
        public int $nodeId,
        public ?int $processId,
        public string $tag,
        public int $port,
        public string $status,
        public int $databasesCount,
        public string $requestId,
        public ?array $databases = null,
    ) {
        if ($id < 1 || $nodeId < 1 || ($processId !== null && $processId < 1) || $databasesCount < 0) {
            throw new InvalidArgumentException('Invalid Database server response identifier.');
        }

        if (strlen($slug) > 63 || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException('Invalid Database server response field [slug].');
        }

        if (preg_match(self::TAG_PATTERN, $tag) !== 1) {
            throw new InvalidArgumentException('Invalid Database server response field [tag].');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid Database server response field [port].');
        }

        if (preg_match(self::STATUS_PATTERN, $status) !== 1) {
            throw new InvalidArgumentException('Invalid Database server response field [status].');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            id: self::integer($data, 'id'),
            slug: self::string($data, 'slug'),
            nodeId: self::integer($data, 'node_id'),
            processId: ($data['process_id'] ?? null) === null ? null : self::integer($data, 'process_id'),
            tag: self::string($data, 'tag'),
            port: self::integer($data, 'port'),
            status: self::string($data, 'status'),
            databasesCount: self::integer($data, 'databases_count'),
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
            databases: array_key_exists('databases', $data) ? self::databases($data['databases'], $requestId) : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'slug' => $this->slug,
            'node_id' => $this->nodeId,
            'process_id' => $this->processId,
            'tag' => $this->tag,
            'port' => $this->port,
            'status' => $this->status,
            'databases_count' => $this->databasesCount,
        ];

        if ($this->databases !== null) {
            $data['databases'] = array_map(
                static function (DatabaseConnectionResponse $connection): array {
                    $item = $connection->toArray();
                    unset($item['request_id']);

                    return $item;
                },
                $this->databases,
            );
        }

        $data['request_id'] = $this->requestId;

        return $data;
    }

    /** @return list<DatabaseConnectionResponse> */
    private static function databases(#[SensitiveParameter] mixed $databases, string $requestId): array
    {
        if (! is_array($databases) || ! array_is_list($databases)) {
            throw new InvalidArgumentException('Invalid Database server response field [databases].');
        }

        $connections = [];

        foreach ($databases as $database) {
            if (! is_array($database)) {
                throw new InvalidArgumentException('Invalid Database server response field [databases].');
            }

            $fields = [];

            foreach ($database as $key => $value) {
                if (! is_string($key)) {
                    throw new InvalidArgumentException('Invalid Database server response field [databases].');
                }

                $fields[$key] = $value;
            }

            $connections[] = DatabaseConnectionResponse::fromGatewayData($fields, $requestId);
        }

        return $connections;
    }

    /** @param array<string, mixed> $data */
    private static function integer(#[SensitiveParameter] array $data, string $key): int
    {
        if (! is_int($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid Database server response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function string(#[SensitiveParameter] array $data, string $key): string
    {
        if (! is_string($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid Database server response field [{$key}].");
        }

        return $data[$key];
    }
}
