<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Data\DatabaseConnections\UpdateDatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseConnectionProfile;
use App\Models\DatabaseConnection;
use Illuminate\Support\Facades\DB;

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

            $locked->update($attributes);

            return $locked->refresh();
        });

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::DatabaseUpdated,
            $result->id,
            DatabaseConnectionData::fromModel($result)->toArray(),
        );

        return $result;
    }
}
