<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\DatabaseConnections\DatabaseInspectionExecutor;
use App\Domain\DatabaseConnections\DatabaseQueryResult;
use App\Domain\DatabaseConnections\DatabaseSqlClassifier;
use App\Models\DatabaseConnection;

final readonly class QueryDatabaseConnectionAction
{
    public function __construct(
        private DatabaseSqlClassifier $classifier,
        private DatabaseInspectionExecutor $executor,
    ) {}

    public function execute(DatabaseConnection $connection, string $sql, bool $write): DatabaseQueryResult
    {
        $sql = $this->classifier->normalize($sql);
        $this->classifier->assertWriteAllowed($sql, $write);

        return $this->executor->query($connection, $sql, $write);
    }
}
