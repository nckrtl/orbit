<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Data\DatabaseConnections\UpdateDatabaseConnectionData;
use App\Domain\DatabaseConnections\DatabaseConnectionProfile;
use App\Models\DatabaseConnection;
use Illuminate\Support\Facades\DB;

final readonly class UpdateDatabaseConnectionAction
{
    public function execute(DatabaseConnection $connection, UpdateDatabaseConnectionData $data): DatabaseConnection
    {
        return DB::transaction(function () use ($connection, $data): DatabaseConnection {
            $locked = DatabaseConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $attributes = DatabaseConnectionProfile::fromUpdate($locked, $data)->attributes();
            unset($attributes['slug']);

            if (! $data->passwordProvided) {
                unset($attributes['password']);
            }

            $locked->update($attributes);

            return $locked->refresh();
        });
    }
}
