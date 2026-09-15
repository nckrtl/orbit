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

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private DatabaseResultRedactor $redactor,
    ) {}

    public function query(DatabaseConnection $connection, string $sql, bool $write): DatabaseQueryResult
    {
        $result = $this->run($connection, $sql, $write);

        if (trim($result->stdout) === '') {
            return $this->redactor->query($connection, new DatabaseQueryResult([], [], 0, false));
        }

        try {
            $decoded = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ResourceOperationException(
                errorCode: 'database.query_failed',
                message: "Database connection [{$connection->slug}] query failed.",
                status: 502,
            );
        }

        if (! is_array($decoded)) {
            throw new ResourceOperationException(
                errorCode: 'database.query_failed',
                message: "Database connection [{$connection->slug}] query failed.",
                status: 502,
            );
        }

        $rows = [];
        $columns = [];
        $truncated = false;

        foreach ($decoded as $row) {
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
                $columns[$name] = $name;
                $normalized[$name] = is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null
                    ? $value
                    : null;
            }

            $rows[] = $normalized;
        }

        return $this->redactor->query(
            $connection,
            new DatabaseQueryResult(array_values($columns), $rows, count($rows), $truncated),
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
    public function arguments(DatabaseConnection $connection, bool $write): array
    {
        $path = $connection->path ?? '';
        $arguments = ['sqlite3', '-json'];

        if (! $write) {
            $arguments[] = '--readonly';
        }

        $arguments[] = '--';
        $arguments[] = $path;

        return $arguments;
    }

    private function run(DatabaseConnection $connection, string $sql, bool $write): CommandResult
    {
        $node = $this->owningNode($connection);
        $input = ProtectedInput::fromString($sql);

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
                new RemoteCommand($this->arguments($connection, $write), protectedInput: $input, timeout: 30.0),
            );
        } finally {
            $input->close();
        }

        if (! $result->succeeded()) {
            throw new ResourceOperationException(
                errorCode: 'database.query_failed',
                message: "Database connection [{$connection->slug}] query failed.",
                status: 502,
            );
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
}
