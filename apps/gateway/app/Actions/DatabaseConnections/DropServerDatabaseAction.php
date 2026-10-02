<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\MysqlStatements;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;

/**
 * Drops the database behind a connection on a Database server: the database, its test databases,
 * and each user Orbit created for it that no other connection on the server still uses. Every
 * statement tolerates a missing object, so a repeated drop succeeds.
 */
final readonly class DropServerDatabaseAction
{
    public function __construct(
        private DatabaseServerAdmin $admin,
        private MysqlStatements $statements,
    ) {}

    public function execute(DatabaseConnection $connection): void
    {
        $server = $connection->server;

        if (! $server instanceof DatabaseServer) {
            return;
        }

        $databases = is_string($connection->database) ? [$connection->database] : [];

        if (is_string($connection->test_database) && is_string($connection->database)) {
            $databases = [
                ...$databases,
                ...$this->admin->execute(
                    $server,
                    $this->statements->databasesNamed($connection->test_database, $connection->test_database),
                ),
            ];
        }

        $sql = '';

        foreach (array_values(array_unique($databases)) as $database) {
            $sql .= $this->statements->dropDatabase($database);
        }

        foreach ($this->unsharedUsers($connection, $server) as $username) {
            $sql .= $this->statements->dropUser($username);
        }

        if ($sql !== '') {
            $this->admin->execute($server, $sql);
        }
    }

    /** @return list<string> */
    private function unsharedUsers(DatabaseConnection $connection, DatabaseServer $server): array
    {
        $usernames = $this->usernames($connection);
        $shared = [];

        $others = DatabaseConnection::query()
            ->with('users')
            ->where('database_server_id', $server->id)
            ->whereKeyNot($connection->id)
            ->get();

        foreach ($others as $other) {
            $shared = [...$shared, ...$this->usernames($other)];
        }

        return array_values(array_diff(array_unique($usernames), $shared));
    }

    /** @return list<string> */
    private function usernames(DatabaseConnection $connection): array
    {
        $usernames = is_string($connection->username) ? [$connection->username] : [];

        foreach ($connection->users as $user) {
            $usernames[] = $user->username;
        }

        return $usernames;
    }
}
