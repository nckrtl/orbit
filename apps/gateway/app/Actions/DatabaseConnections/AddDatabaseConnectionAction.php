<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Data\DatabaseConnections\AddDatabaseConnectionData;
use App\Domain\DatabaseConnections\DatabaseConnectionProfile;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class AddDatabaseConnectionAction
{
    public function execute(AddDatabaseConnectionData $data): DatabaseConnection
    {
        $profile = DatabaseConnectionProfile::fromAdd($data);

        try {
            return DB::transaction(function () use ($profile, $data): DatabaseConnection {
                $existing = DatabaseConnection::query()
                    ->where('slug', $data->slug)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof DatabaseConnection) {
                    throw new ResourceOperationException(
                        errorCode: 'database.slug_conflict',
                        message: "Database connection [{$data->slug}] already exists.",
                        status: 409,
                    );
                }

                return DatabaseConnection::query()->create($profile->attributes());
            });
        } catch (UniqueConstraintViolationException) {
            throw new ResourceOperationException(
                errorCode: 'database.slug_conflict',
                message: "Database connection [{$data->slug}] already exists.",
                status: 409,
            );
        }
    }
}
