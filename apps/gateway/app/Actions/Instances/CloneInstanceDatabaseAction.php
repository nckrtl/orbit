<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\DatabaseConnections\AttachDatabaseConnectionAction;
use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Actions\DatabaseConnections\DropOwnedDatabasesAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\MysqlNames;
use App\Domain\Instances\DatabaseClone\DatabaseCloneStep;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlan;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlanner;
use App\Domain\Instances\DatabaseClone\InstanceSqliteCloner;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use Throwable;

/**
 * Gives a new development Instance its own copy of the `default` Instance's `DB` database, of the
 * same kind, records it as owned by the Instance, attaches it under prefix DB, and synchronizes
 * `.env` and `.env.testing`. A failure drops what the clone made and throws
 * `instance.database_clone_failed`.
 */
final readonly class CloneInstanceDatabaseAction
{
    public const string SQLITE_TEST_DATABASE = ':memory:';

    public function __construct(
        private CreateServerDatabaseAction $serverDatabases,
        private DatabaseServerAdmin $admin,
        private InstanceSqliteCloner $sqlite,
        private AttachDatabaseConnectionAction $attach,
        private ImportInstanceEnvironmentAction $import,
        private SynchronizeInstanceEnvironmentAction $synchronize,
        private DropOwnedDatabasesAction $drops,
        private MysqlNames $names,
        private RecordEventBroadcaster $broadcaster,
    ) {}

    public function execute(Instance $instance, InstanceDatabaseClonePlan $plan): DatabaseConnection
    {
        $instance->loadMissing(['project', 'node']);
        $settled = $this->settledDatabase($instance);

        if ($settled instanceof DatabaseConnection) {
            return $settled;
        }

        try {
            $this->importEnvironment($instance);
            $connection = $this->recorded($instance, $plan);

            if ($connection->clone_step === DatabaseCloneStep::Recorded) {
                $this->fill($instance, $plan, $connection);
                $connection->update(['clone_step' => DatabaseCloneStep::Filled]);
            }

            $this->attach->execute($instance, $connection, InstanceDatabaseClonePlanner::PREFIX);
            $this->synchronize->execute($instance);

            if ($connection->clone_step === DatabaseCloneStep::Filled) {
                $connection->update(['clone_step' => DatabaseCloneStep::Complete]);
            }

            return $connection->refresh();
        } catch (Throwable $exception) {
            $this->discard($instance);

            throw new ResourceOperationException(
                errorCode: 'instance.database_clone_failed',
                message: "The database of Instance [{$instance->name}] could not be copied from the default Instance.",
                status: 502,
                previous: $exception,
            );
        }
    }

    /** True when the Instance's `DB` copy finished, or when it owns a `DB` database Orbit did not copy. */
    public function isComplete(Instance $instance): bool
    {
        return $this->settledDatabase($instance) instanceof DatabaseConnection;
    }

    /** The connection slug of an Instance's copy, such as `acme-feature-x`. */
    public function slug(Instance $instance): string
    {
        $instance->loadMissing('project');
        $slug = "{$instance->project->slug}-{$instance->name}";

        if (strlen($slug) <= 63) {
            return $slug;
        }

        return rtrim(substr($slug, 0, 54), '-').'-'.substr(sha1($slug), 0, 8);
    }

    private function settledDatabase(Instance $instance): ?DatabaseConnection
    {
        $target = DatabaseConnectionTarget::query()
            ->with('databaseConnection')
            ->where('instance_id', $instance->id)
            ->where('prefix', InstanceDatabaseClonePlanner::PREFIX)
            ->first();

        if (! $target instanceof DatabaseConnectionTarget) {
            return null;
        }

        $connection = $target->databaseConnection;

        if (
            $connection->owner_instance_id !== $instance->id
            || ! in_array($connection->clone_step, [null, DatabaseCloneStep::Complete], true)
        ) {
            return null;
        }

        return $connection;
    }

    /**
     * Before the first synchronization replaces `.env`, import the file that is there, so its
     * other keys stay. An Instance without `.env` keeps only what is stored.
     */
    private function importEnvironment(Instance $instance): void
    {
        if (InstanceEnvironmentValue::query()->where('instance_id', $instance->id)->exists()) {
            return;
        }

        $this->import->importExisting($instance);
    }

    /** The copy's connection record, written before anything on a server or Node changes. */
    private function recorded(Instance $instance, InstanceDatabaseClonePlan $plan): DatabaseConnection
    {
        $slug = $this->slug($instance);
        $existing = DatabaseConnection::query()
            ->with('server')
            ->where('owner_instance_id', $instance->id)
            ->where('slug', $slug)
            ->first();

        if ($existing instanceof DatabaseConnection) {
            return $existing;
        }

        if (is_string($plan->relativePath)) {
            $connection = DatabaseConnection::query()->create([
                'slug' => $slug,
                'driver' => DatabaseDriver::Sqlite->value,
                'node_id' => $instance->node_id,
                'owner_instance_id' => $instance->id,
                'clone_step' => DatabaseCloneStep::Recorded,
                'test_database' => self::SQLITE_TEST_DATABASE,
                'path' => rtrim($instance->checkout_path, '/').'/'.$plan->relativePath,
            ]);

            $this->broadcaster->broadcast(
                RecordEventType::DatabaseCreated,
                $connection->id,
                DatabaseConnectionData::fromModel($connection)->toArray(),
            );

            return $connection;
        }

        if (! $plan->server instanceof DatabaseServer) {
            throw new ResourceOperationException('instance.database_clone_unsupported', 'The default database is not on a Database server.', 422);
        }

        return $this->serverDatabases->reserve(
            server: $plan->server,
            slug: $slug,
            database: $this->names->database("{$instance->project->slug}_{$instance->name}"),
            instance: $instance,
            createdBy: null,
            attributes: ['clone_step' => DatabaseCloneStep::Recorded],
        )->load('server');
    }

    /** Copy the data. A retry copies again, because only a finished copy moves the step on. */
    private function fill(Instance $instance, InstanceDatabaseClonePlan $plan, DatabaseConnection $connection): void
    {
        if (is_string($plan->relativePath)) {
            if (! is_string($connection->path)) {
                throw new ResourceOperationException('instance.database_clone_failed', 'The copy has no path.', 502);
            }

            $this->sqlite->copy(
                $plan->source,
                rtrim($plan->source->checkout_path, '/').'/'.$plan->relativePath,
                $instance,
                $connection->path,
            );

            return;
        }

        $server = $connection->server;
        $source = $plan->connection->database;
        $target = $connection->database;

        if (! $server instanceof DatabaseServer || ! is_string($source) || ! is_string($target)) {
            throw new ResourceOperationException('instance.database_clone_unsupported', 'The default database is not on a Database server.', 422);
        }

        $this->serverDatabases->provisionEmpty($connection);
        $this->admin->copyDatabase($server, $source, $target);
    }

    /** Drop what a failed clone made. The Instance removal that follows drops it again if this fails. */
    private function discard(Instance $instance): void
    {
        try {
            $this->drops->execute($instance->id);
        } catch (Throwable) {
            // The removal of the Instance runs the same drop.
        }
    }
}
