<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlanner;
use App\Models\DatabaseConnectionTarget;

/**
 * The `.env.testing` keys for an Instance that owns its `DB` database: the `DB_*` keys of that connection with
 * DB_DATABASE pointing to the test database. Null when the Instance does not own its `DB` database.
 */
final readonly class InstanceTestEnvironment
{
    public function __construct(
        private DatabaseConnectionEnvProjection $projection,
    ) {}

    public function plan(int $instanceId): ?InstanceTestEnvironmentPlan
    {
        $target = DatabaseConnectionTarget::query()
            ->with('databaseConnection')
            ->where('instance_id', $instanceId)
            ->where('prefix', InstanceDatabaseClonePlanner::PREFIX)
            ->first();

        if (! $target instanceof DatabaseConnectionTarget) {
            return null;
        }

        $connection = $target->databaseConnection;

        if ($connection->owner_instance_id !== $instanceId || ! is_string($connection->test_database)) {
            return null;
        }

        $prefix = InstanceDatabaseClonePlanner::PREFIX;
        $values = $this->projection->project($connection, $prefix)['values'];
        $values[$this->projection->key($prefix, 'DATABASE')] = $connection->test_database;

        return new InstanceTestEnvironmentPlan(
            values: $values,
            managedKeys: $this->projection->managedKeys($prefix),
            testDatabase: $connection->test_database,
        );
    }
}
