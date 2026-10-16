<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\ProxyCli\ReconvergeProxyCliConnectionAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Data\DatabaseConnections\UpdateDatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseConnectionProfile;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UpdateDatabaseConnectionAction
{
    public function __construct(private ?RecordEventBroadcaster $broadcaster = null) {}

    public function execute(DatabaseConnection $connection, UpdateDatabaseConnectionData $data): DatabaseConnection
    {
        $result = DB::transaction(function () use ($connection, $data): DatabaseConnection {
            $locked = DatabaseConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $attributes = DatabaseConnectionProfile::fromUpdate($locked, $data)->attributes();
            unset($attributes['slug']);

            if (! $data->passwordProvided) {
                unset($attributes['password']);
            }

            $proposed = clone $locked;
            $proposed->fill($attributes);

            if ($locked->server?->driver === DatabaseDriver::Redis) {
                foreach (['driver', 'node_id', 'host', 'port', 'path', 'username', 'password'] as $field) {
                    if ($proposed->{$field} !== $locked->{$field}) {
                        throw new ResourceOperationException(
                            errorCode: 'database.server_connection_managed',
                            message: 'Create a new Valkey connection and move its clients instead of changing its identity or credentials.',
                            status: 409,
                        );
                    }
                }
            }

            if ($locked->server?->driver === DatabaseDriver::Redis && $data->databaseProvided && $proposed->database !== null && (! ctype_digit($proposed->database) || (int) $proposed->database >= app(ValkeyServerAdmin::class)->databaseCount($locked->server))) {
                throw ValidationException::withMessages(['database' => ['The database index is outside the range configured on this server.']]);
            }

            if ($proposed->driver === DatabaseDriver::Sqlite) {
                foreach ($locked->targets()->with('instance')->get() as $target) {
                    if ($target->instance->node_id !== $proposed->node_id) {
                        throw new ResourceOperationException(
                            errorCode: 'database.node_mismatch',
                            message: 'A SQLite connection must be on the same Node as every attached Instance.',
                            status: 422,
                        );
                    }
                }
            }

            app(ReconvergeProxyCliConnectionAction::class)->validate($proposed);
            $locked->update($attributes);

            return $locked->refresh();
        });

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::DatabaseUpdated,
            $result->id,
            DatabaseConnectionData::fromModel($result)->toArray(),
        );

        app(ReconvergeProxyCliConnectionAction::class)->execute($result);

        return $result;
    }
}
