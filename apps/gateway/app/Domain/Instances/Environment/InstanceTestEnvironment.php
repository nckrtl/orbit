<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlanner;
use App\Models\DatabaseConnectionTarget;
use SensitiveParameter;

/**
 * The values of `.env.testing` for an Instance that owns its `DB` database: the stored values
 * with APP_ENV=testing and DB_DATABASE pointing to the test database. Null when the Instance
 * does not own its `DB` database.
 */
final readonly class InstanceTestEnvironment
{
    /**
     * @param  array<string, string>  $values
     * @return array<string, string>|null
     */
    public function values(int $instanceId, #[SensitiveParameter] array $values): ?array
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

        return [
            ...$values,
            'APP_ENV' => 'testing',
            InstanceDatabaseClonePlanner::PREFIX.'_DATABASE' => $connection->test_database,
        ];
    }
}
