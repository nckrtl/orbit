<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseQueryResult;
use App\Domain\DatabaseConnections\DatabaseSchemaTable;
use App\Domain\DatabaseConnections\DatabaseTableColumn;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\DatabaseConnection;
use App\Models\Node;
use JsonException;

final readonly class SqliteNodeDatabaseInspector
{
    public const int ROW_LIMIT = 500;

    public const string REMOTE_COMMAND = 'internal:database-query-local';

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private DatabaseResultRedactor $redactor,
    ) {}

    public function query(DatabaseConnection $connection, string $sql, bool $write): DatabaseQueryResult
    {
        $result = $this->run($connection, $sql, $write);

        try {
            $decoded = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail($connection);
        }

        if (! is_array($decoded) || isset($decoded['error'])) {
            $this->fail($connection);
        }

        $columns = [];
        $rows = [];
        $truncated = $decoded['truncated'] === true;

        foreach ($decoded['columns'] ?? [] as $column) {
            if (is_string($column)) {
                $columns[] = $column;
            }
        }

        foreach ($decoded['rows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (count($rows) >= self::ROW_LIMIT) {
                $truncated = true;

                break;
            }

            $normalized = [];

            foreach ($row as $key => $value) {
                $name = (string) $key;
                $normalized[$name] = is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null
                    ? $value
                    : null;
            }

            $rows[] = $normalized;
        }

        $rowCount = $decoded['row_count'] ?? count($rows);
        $rowCount = is_int($rowCount) ? max($rowCount, 0) : count($rows);

        return $this->redactor->query(
            $connection,
            new DatabaseQueryResult($columns, $rows, $rowCount, $truncated),
        );
    }

    /** @return list<string> */
    public function tables(DatabaseConnection $connection): array
    {
        $result = $this->query(
            $connection,
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            false,
        );
        $tables = [];

        foreach ($result->rows as $row) {
            if (is_string($row['name'] ?? null) && $row['name'] !== '') {
                $tables[] = $row['name'];
            }
        }

        return $this->redactor->tables($connection->password, $tables);
    }

    /** @return list<DatabaseSchemaTable> */
    public function schema(DatabaseConnection $connection): array
    {
        $tables = [];

        foreach ($this->tables($connection) as $table) {
            $tables[] = new DatabaseSchemaTable($table, $this->describe($connection, $table));
        }

        return $this->redactor->schema($connection->password, $tables);
    }

    /** @return list<DatabaseTableColumn> */
    public function describe(DatabaseConnection $connection, string $table): array
    {
        $quoted = '"'.str_replace('"', '""', $table).'"';
        $result = $this->query($connection, 'PRAGMA table_info('.$quoted.')', false);
        $columns = array_map(static fn (array $row): DatabaseTableColumn => new DatabaseTableColumn(
            (string) ($row['name'] ?? ''),
            (string) ($row['type'] ?? ''),
            ! (bool) ($row['notnull'] ?? false),
            isset($row['dflt_value']) ? (string) $row['dflt_value'] : null,
            (int) ($row['pk'] ?? 0) > 0,
        ), $result->rows);

        if ($columns === []) {
            throw new ResourceOperationException(
                errorCode: 'database.table_missing',
                message: "Table [{$table}] was not found.",
                status: 404,
            );
        }

        return $this->redactor->columns($connection->password, $columns);
    }

    /**
     * @return non-empty-list<string>
     */
    public function arguments(): array
    {
        return ['orbit', self::REMOTE_COMMAND];
    }

    /**
     * @return array{token: string, path: string, sql: string, write: bool}
     */
    public function payload(DatabaseConnection $connection, string $sql, bool $write, string $token): array
    {
        return [
            'token' => $token,
            'path' => $connection->path ?? '',
            'sql' => $sql,
            'write' => $write,
        ];
    }

    private function run(DatabaseConnection $connection, string $sql, bool $write): CommandResult
    {
        $node = $this->owningNode($connection);
        $token = bin2hex(random_bytes(32));
        $input = ProtectedInput::fromString(json_encode(
            $this->payload($connection, $sql, $write, $token),
            JSON_THROW_ON_ERROR,
        ));

        try {
            $result = $this->ssh->execute(
                new SshConnection(
                    host: (string) $node->wireguard_ip,
                    user: $node->user,
                    port: 22,
                    identityFile: $this->keys->privateKeyPath(),
                    knownHostsFile: $this->knownHosts->path(),
                    commandTimeout: 30.0,
                ),
                new RemoteCommand($this->arguments(), protectedInput: $input, timeout: 30.0),
            );
        } finally {
            $input->close();
        }

        if (! $result->succeeded()) {
            $this->fail($connection);
        }

        return $result;
    }

    private function owningNode(DatabaseConnection $connection): Node
    {
        if ($connection->node_id === null) {
            throw new ResourceOperationException(
                errorCode: 'database.sqlite_node_required',
                message: "SQLite connection [{$connection->slug}] requires an associated Node.",
            );
        }

        $node = $connection->node ?? Node::query()->find($connection->node_id);

        if (! $node instanceof Node) {
            throw new ResourceOperationException(
                errorCode: 'database.node_missing',
                message: "Database connection [{$connection->slug}] names a Node that is gone.",
                status: 422,
            );
        }

        if ($node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                errorCode: 'database.sqlite_node_inactive',
                message: "SQLite connection [{$connection->slug}] requires an active Node.",
            );
        }

        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException(
                errorCode: 'database.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
            );
        }

        if (! is_string($connection->path) || $connection->path === '') {
            throw new ResourceOperationException(
                errorCode: 'database.query_failed',
                message: "Database connection [{$connection->slug}] query failed.",
                status: 502,
            );
        }

        return $node;
    }

    private function fail(DatabaseConnection $connection): never
    {
        throw new ResourceOperationException(
            errorCode: 'database.query_failed',
            message: "Database connection [{$connection->slug}] query failed.",
            status: 502,
        );
    }
}
