<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Domain\DatabaseServers\MysqlNames;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use Throwable;

/**
 * Gives a new development Instance an empty database on a Database server before its setup steps
 * run. It is `database:create --server --instance`: the database, its test database, and the
 * Instance's user, owned by the Instance and attached under prefix DB. Then it synchronizes `.env`
 * and `.env.testing`, so a setup step such as a migration finds the database.
 */
final readonly class CreateInstanceServerDatabaseAction
{
    public function __construct(
        private CreateServerDatabaseAction $serverDatabases,
        private CloneInstanceDatabaseAction $clones,
        private ImportInstanceEnvironmentAction $import,
        private SynchronizeInstanceEnvironmentAction $synchronize,
        private MysqlNames $names,
    ) {}

    public function execute(Instance $instance, DatabaseServer $server): DatabaseConnection
    {
        $instance->loadMissing(['project', 'node']);

        try {
            // Keep the keys of an existing `.env` before the first synchronization replaces the file.
            if (! InstanceEnvironmentValue::query()->where('instance_id', $instance->id)->exists()) {
                $this->import->importExisting($instance);
            }

            $connection = $this->serverDatabases->createOnServer(
                server: $server,
                slug: $this->clones->slug($instance),
                database: $this->names->database("{$instance->project->slug}_{$instance->name}"),
                instance: $instance,
                createdBy: null,
            );
            $this->synchronize->execute($instance);

            return $connection;
        } catch (ResourceOperationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ResourceOperationException(
                errorCode: 'instance.database_create_failed',
                message: "The database of Instance [{$instance->name}] could not be created on Database server [{$server->slug}].",
                status: 502,
                previous: $exception,
            );
        }
    }
}
