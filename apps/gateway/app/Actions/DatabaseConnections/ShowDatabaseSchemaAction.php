<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseInspectionExecutor;
use App\Domain\DatabaseConnections\DatabaseSchemaTable;
use App\Models\DatabaseConnection;

final readonly class ShowDatabaseSchemaAction
{
    public function __construct(
        private DatabaseInspectionExecutor $executor,
    ) {}

    /** @return list<DatabaseSchemaTable> */
    public function execute(DatabaseConnection $connection): array
    {
        return $this->executor->schema($connection);
    }
}
