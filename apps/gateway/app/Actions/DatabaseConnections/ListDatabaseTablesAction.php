<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseInspectionExecutor;
use App\Models\DatabaseConnection;

final readonly class ListDatabaseTablesAction
{
    public function __construct(
        private DatabaseInspectionExecutor $executor,
    ) {}

    /** @return list<string> */
    public function execute(DatabaseConnection $connection): array
    {
        return $this->executor->tables($connection);
    }
}
