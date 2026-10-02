<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\DatabaseClone\InstanceSqliteCloner;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use Illuminate\Support\Facades\DB;

/**
 * Drops every database an Instance owns and deletes its connection record: a database on a
 * Database server with its test databases and unshared users, or a SQLite file. A database that
 * is already gone counts as dropped. A database the Instance only has attached stays.
 */
final readonly class DropOwnedDatabasesAction
{
    public function __construct(
        private DropServerDatabaseAction $serverDatabases,
        private InstanceSqliteCloner $sqlite,
        private RecordEventBroadcaster $broadcaster,
    ) {}

    public function execute(int $instanceId): void
    {
        $connections = DatabaseConnection::query()
            ->with(['server', 'ownerInstance.node'])
            ->where('owner_instance_id', $instanceId)
            ->orderBy('id')
            ->get();

        foreach ($connections as $connection) {
            $this->drop($connection);
        }
    }

    public function drop(DatabaseConnection $connection): void
    {
        if ($connection->database_server_id !== null) {
            $this->serverDatabases->execute($connection);
        } elseif ($connection->driver === DatabaseDriver::Sqlite && is_string($connection->path)) {
            $owner = $connection->ownerInstance;

            if ($owner instanceof Instance) {
                $this->sqlite->remove($owner, $connection->path);
            }
        }

        DB::transaction(static function () use ($connection): void {
            DatabaseConnectionTarget::query()->where('database_connection_id', $connection->id)->delete();
            $connection->delete();
        });

        $this->broadcaster->broadcast(
            RecordEventType::DatabaseDeleted,
            $connection->id,
            ['id' => $connection->id, 'slug' => $connection->slug],
        );
    }
}
