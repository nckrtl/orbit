<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseInspectionExecutor;
use App\Domain\DatabaseConnections\DatabaseTableColumn;
use App\Domain\DatabaseConnections\DatabaseTableName;
use App\Models\DatabaseConnection;

final readonly class DescribeDatabaseTableAction
{
    public function __construct(
        private DatabaseInspectionExecutor $executor,
    ) {}

    /** @return list<DatabaseTableColumn> */
    public function execute(DatabaseConnection $connection, string $table): array
    {
        return $this->executor->describe($connection, DatabaseTableName::normalize($table));
    }
}
