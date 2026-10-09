<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlanner;
use App\Models\DatabaseConnectionTarget;
use SensitiveParameter;

/**
 * The `.env.testing` plan for an Instance that owns its `DB` database. Its values seed a missing file: the stored
 * values with APP_ENV=testing and the `DB_*` keys of that connection, with DB_DATABASE pointing to the test database.
 * An existing file takes only the managed `DB_*` keys. Null when the Instance does not own its `DB` database.
 */
final readonly class InstanceTestEnvironment
{
    public function __construct(
        private DatabaseConnectionEnvProjection $projection,
    ) {}

    /** @param  array<string, string>  $values  the stored values that `.env` renders */
    public function plan(int $instanceId, #[SensitiveParameter] array $values): ?InstanceTestEnvironmentPlan
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
        $managedKeys = $this->projection->managedKeys($prefix);
        $database = $this->projection->project($connection, $prefix)['values'];
        $database[$this->projection->key($prefix, 'DATABASE')] = $connection->test_database;

        return new InstanceTestEnvironmentPlan(
            values: [
                ...array_diff_key($values, array_flip($managedKeys)),
                ...$database,
                'APP_ENV' => 'testing',
            ],
            managedKeys: $managedKeys,
            testDatabase: $connection->test_database,
        );
    }
}
