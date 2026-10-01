<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Data\DatabaseConnections\CreateDatabaseUserData;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\MysqlStatements;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;

/**
 * Adds a user to a database on a Database server. A read-only user gets SELECT, any other user
 * all privileges. Running it again sets the password and replaces the privileges.
 */
final readonly class CreateDatabaseUserAction
{
    public function __construct(
        private DatabaseServerAdmin $admin,
        private MysqlStatements $statements,
    ) {}

    public function execute(
        DatabaseConnection $connection,
        CreateDatabaseUserData $data,
        string $createdBy,
    ): DatabaseUser {
        $server = $connection->server;

        if (! $server instanceof DatabaseServer || ! is_string($connection->database)) {
            throw new ResourceOperationException(
                errorCode: 'database.server_required',
                message: "Database connection [{$connection->slug}] is not on a Database server.",
                status: 422,
            );
        }

        $ownsConnection = DatabaseConnection::query()
            ->where('database_server_id', $server->id)
            ->where('username', $data->username)
            ->exists();

        if ($ownsConnection) {
            throw new ResourceOperationException(
                errorCode: 'database.name_conflict',
                message: "User [{$data->username}] belongs to a connection on Database server [{$server->slug}].",
                status: 409,
            );
        }

        $this->admin->execute(
            $server,
            $this->statements->ensureUser($data->username, $data->password)
                .$this->statements->replaceGrant($connection->database, $data->username, $data->readOnly),
        );

        return DatabaseUser::query()->updateOrCreate(
            [
                'database_connection_id' => $connection->id,
                'username' => $data->username,
            ],
            [
                'privileges' => $this->statements->privilegeText($connection->database, $data->readOnly),
                'created_by' => $createdBy,
            ],
        );
    }
}
