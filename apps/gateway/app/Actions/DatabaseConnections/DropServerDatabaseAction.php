<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\MysqlStatements;
use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Drops the database behind a connection on a Database server: the database, its test databases,
 * and each user Orbit created for it that no other connection on the server still uses. Every
 * statement tolerates a missing object, so a repeated drop succeeds.
 *
 * On a Valkey server it deletes only the connection's ACL user, never its keys. A connection that
 * logs in as `default`, such as one linked by adoption, keeps the admin user.
 */
final readonly class DropServerDatabaseAction
{
    public function __construct(
        private DatabaseServerAdmin $admin,
        private MysqlStatements $statements,
        private ValkeyServerAdmin $valkey,
    ) {}

    /** Keep the record removal inside the ACL lock when the caller removes the connection too. */
    public function execute(DatabaseConnection $connection, ?Closure $afterDrop = null): void
    {
        $server = $connection->server;
        if (! $server instanceof DatabaseServer || $server->driver !== DatabaseDriver::Redis) {
            $this->drop($connection);
            $afterDrop?->__invoke();

            return;
        }

        $lock = Cache::lock('database-server:'.$server->id.':acl', 120);
        if (! $lock->get()) {
            throw new ResourceOperationException('database.server_in_use', 'Another ACL change is in progress.', 409);
        }

        try {
            $current = $connection->fresh(['server', 'users']);
            if ($current === null) {
                return;
            }
            $this->drop($current);
            $afterDrop?->__invoke();
        } finally {
            $lock->release();
        }
    }

    private function drop(DatabaseConnection $connection): void
    {
        $server = $connection->server;

        if (! $server instanceof DatabaseServer) {
            return;
        }

        if ($server->driver === DatabaseDriver::Redis) {
            foreach ($this->unsharedUsers($connection, $server) as $username) {
                if ($username !== '' && $username !== ValkeyAclUser::ADMIN) {
                    $this->valkey->deleteUser($server, $username);
                }
            }

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
