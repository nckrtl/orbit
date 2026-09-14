<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Models\DatabaseConnection;

final readonly class RemoveDatabaseConnectionAction
{
    public function execute(DatabaseConnection $connection): void
    {
        $connection->delete();
    }
}
