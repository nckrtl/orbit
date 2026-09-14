<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;

final readonly class RemoveDatabaseConnectionAction
{
    public function execute(DatabaseConnection $connection): void
    {
        if ($connection->targets()->exists()) {
            throw new ResourceOperationException(
                errorCode: 'database.connection_attached',
                message: "Detach Database connection [{$connection->slug}] from every AppInstance before removing it.",
                status: 409,
            );
        }

        $connection->delete();
    }
}
