<?php

declare(strict_types=1);

namespace App\Domain\Instances\DatabaseClone;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Project;

/**
 * Decides whether a new development Instance gets a copy of the `default` Instance's database.
 * It reads only Gateway records, so a refusal happens before anything changes.
 */
final readonly class InstanceDatabaseClonePlanner
{
    public const string SOURCE_INSTANCE = 'default';

    public const string PREFIX = 'DB';

    public function plan(Project $project, string $instanceName): ?InstanceDatabaseClonePlan
    {
        if ($instanceName === self::SOURCE_INSTANCE) {
            return null;
        }

        $source = Instance::query()
            ->with('node')
            ->where('project_id', $project->id)
            ->where('name', self::SOURCE_INSTANCE)
            ->first();

        if (! $source instanceof Instance) {
            return null;
        }

        $target = DatabaseConnectionTarget::query()
            ->with('databaseConnection.server')
            ->where('instance_id', $source->id)
            ->where('prefix', self::PREFIX)
            ->first();

        if (! $target instanceof DatabaseConnectionTarget) {
            return null;
        }

        $connection = $target->databaseConnection;

        if ($connection->driver === DatabaseDriver::Mysql) {
            $server = $connection->server;

            if (! $server instanceof DatabaseServer || ! is_string($connection->database)) {
                throw $this->unsupported('The default Instance\'s MySQL database is not on a Database server.');
            }

            return new InstanceDatabaseClonePlan($source, $connection, $server, null);
        }

        if ($connection->driver === DatabaseDriver::Sqlite) {
            $path = $connection->path;
            $checkout = rtrim($source->checkout_path, '/');

            if (
                ! is_string($path)
                || ! str_starts_with($path, $checkout.'/')
                || preg_match('#(?:^|/)\.{1,2}(?:/|$)|//#', $path) === 1
            ) {
                throw $this->unsupported('The default Instance\'s SQLite database is not inside its checkout.');
            }

            return new InstanceDatabaseClonePlan($source, $connection, null, substr($path, strlen($checkout) + 1));
        }

        throw $this->unsupported("Orbit cannot copy a [{$connection->driver->value}] database.");
    }

    private function unsupported(string $message): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'instance.database_clone_unsupported',
            message: $message,
            status: 422,
        );
    }
}
