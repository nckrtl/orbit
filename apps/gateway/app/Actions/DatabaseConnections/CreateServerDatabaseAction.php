<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Data\DatabaseConnections\CreateServerDatabaseData;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\MysqlNames;
use App\Domain\DatabaseServers\MysqlStatements;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Instance;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

/**
 * Creates a MySQL database and its user on a Database server and records the connection.
 *
 * With an Instance, the user is the Instance's one user on that server. It also gets the test
 * database `<name>_test` and every database whose name starts with it, and the connection is
 * attached to the Instance under prefix DB. Without an Instance, the database gets its own user.
 *
 * On a Valkey server it creates one ACL user named after the slug instead, and records the `redis`
 * connection that logs in as that user. A Valkey server holds no Instance databases.
 */
final readonly class CreateServerDatabaseAction
{
    public const string ATTACH_PREFIX = 'DB';

    private const int PASSWORD_LENGTH = 40;

    public function __construct(
        private DatabaseServerAdmin $admin,
        private MysqlNames $names,
        private MysqlStatements $statements,
        private AttachDatabaseConnectionAction $attach,
        private RecordEventBroadcaster $broadcaster,
        private ValkeyServerAdmin $valkey,
    ) {}

    public function execute(CreateServerDatabaseData $data, ?string $createdBy): DatabaseConnection
    {
        $server = $this->server($data->server);

        if ($server->driver === DatabaseDriver::Redis && $data->instanceId === null) {
            $lock = Cache::lock("database-server:{$server->id}:acl", 120);
            if (! $lock->get()) {
                throw new ResourceOperationException(
                    errorCode: 'database.server_in_use',
                    message: "Database server [{$server->slug}] is changing ACL users. Try again.",
                    status: 409,
                );
            }

            try {
                return $this->createAclUser($server->refresh(), $data, $createdBy);
            } finally {
                $lock->release();
            }
        }

        $instance = $data->instanceId === null
            ? null
            : Instance::query()->with('project')->findOrFail($data->instanceId);

        return $this->createOnServer(
            server: $server,
            slug: $data->slug,
            database: $this->names->database($data->slug),
            instance: $instance,
            createdBy: $createdBy,
        );
    }

    /** Create one database on the server and record its connection. */
    public function createOnServer(
        DatabaseServer $server,
        string $slug,
        string $database,
        ?Instance $instance,
        ?string $createdBy,
    ): DatabaseConnection {
        $this->assertActive($server);
        $this->assertHoldsDatabases($server);

        if (DatabaseConnection::query()->where('slug', $slug)->exists()) {
            throw $this->slugConflict($slug);
        }

        $testDatabase = $instance instanceof Instance ? $this->names->testDatabase($database) : null;
        $credentials = $this->credentials($server, $database, $instance);

        $this->assertNamesFree($server, $database, $testDatabase, $credentials);

        $sql = $this->statements->ensureUser($credentials['username'], $credentials['password'])
            .$this->statements->createDatabase($database)
            .$this->statements->grantAll($database, $credentials['username']);

        if ($testDatabase !== null) {
            $sql .= $this->statements->createDatabase($testDatabase)
                .$this->statements->grantAllWithPrefix($testDatabase, $credentials['username']);
        }

        try {
            $this->admin->execute($server, $sql);
        } catch (ResourceOperationException $exception) {
            $this->undo($server, $database, $testDatabase, $credentials);

            throw $exception;
        }

        try {
            $connection = DB::transaction(function () use (
                $slug,
                $server,
                $instance,
                $database,
                $testDatabase,
                $credentials,
                $createdBy,
            ): DatabaseConnection {
                $connection = DatabaseConnection::query()->create([
                    'slug' => $slug,
                    'driver' => DatabaseDriver::Mysql->value,
                    'node_id' => $server->node_id,
                    'database_server_id' => $server->id,
                    'owner_instance_id' => $instance?->id,
                    'host' => $server->node->wireguard_ip,
                    'port' => $server->port,
                    'database' => $database,
                    'test_database' => $testDatabase,
                    'path' => null,
                    'username' => $credentials['username'],
                    'password' => $credentials['password'],
                ]);

                DatabaseUser::query()->create([
                    'database_connection_id' => $connection->id,
                    'username' => $credentials['username'],
                    'privileges' => $this->statements->privilegeText($database, prefix: $testDatabase),
                    'created_by' => $createdBy,
                ]);

                return $connection;
            });
        } catch (UniqueConstraintViolationException) {
            $this->undo($server, $database, $testDatabase, $credentials);

            throw $this->slugConflict($slug);
        }

        $this->broadcaster->broadcast(
            RecordEventType::DatabaseCreated,
            $connection->id,
            DatabaseConnectionData::fromModel($connection)->toArray(),
        );

        if ($instance instanceof Instance) {
            $this->attach->execute($instance, $connection, self::ATTACH_PREFIX);
        }

        return $connection;
    }

    /**
     * Record an Instance's database before it exists on the server, with the names and the
     * credentials it will get. A retry reads them back, so the copy keeps one user and password.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function reserve(
        DatabaseServer $server,
        string $slug,
        string $database,
        Instance $instance,
        ?string $createdBy,
        array $attributes = [],
    ): DatabaseConnection {
        $this->assertActive($server);
        $this->assertHoldsDatabases($server);

        if (DatabaseConnection::query()->where('slug', $slug)->exists()) {
            throw $this->slugConflict($slug);
        }

        $testDatabase = $this->names->testDatabase($database);
        $credentials = $this->credentials($server, $database, $instance);
        $this->assertNamesFree($server, $database, $testDatabase, $credentials);

        try {
            $connection = DB::transaction(function () use (
                $slug,
                $server,
                $instance,
                $database,
                $testDatabase,
                $credentials,
                $createdBy,
                $attributes,
            ): DatabaseConnection {
                $connection = DatabaseConnection::query()->create([
                    ...$attributes,
                    'slug' => $slug,
                    'driver' => DatabaseDriver::Mysql->value,
                    'node_id' => $server->node_id,
                    'database_server_id' => $server->id,
                    'owner_instance_id' => $instance->id,
                    'host' => $server->node->wireguard_ip,
                    'port' => $server->port,
                    'database' => $database,
                    'test_database' => $testDatabase,
                    'path' => null,
                    'username' => $credentials['username'],
                    'password' => $credentials['password'],
                ]);

                DatabaseUser::query()->create([
                    'database_connection_id' => $connection->id,
                    'username' => $credentials['username'],
                    'privileges' => $this->statements->privilegeText($database, prefix: $testDatabase),
                    'created_by' => $createdBy,
                ]);

                return $connection;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->slugConflict($slug);
        }

        $this->broadcaster->broadcast(
            RecordEventType::DatabaseCreated,
            $connection->id,
            DatabaseConnectionData::fromModel($connection)->toArray(),
        );

        return $connection;
    }

    /**
     * Make the recorded user, an empty database, and an empty test database on the server. Any
     * database already there is dropped first, so a retry never trusts a partial copy.
     */
    public function provisionEmpty(DatabaseConnection $connection): void
    {
        $server = $connection->server;

        if (
            ! $server instanceof DatabaseServer
            || ! is_string($connection->database)
            || ! is_string($connection->test_database)
            || ! is_string($connection->username)
            || ! is_string($connection->password)
        ) {
            throw new ResourceOperationException('database.server_required', "Database connection [{$connection->slug}] is not on a Database server.", 422);
        }

        $this->assertActive($server);

        $this->admin->execute(
            $server,
            $this->statements->ensureUser($connection->username, $connection->password)
                .$this->statements->dropDatabase($connection->database)
                .$this->statements->dropDatabase($connection->test_database)
                .$this->statements->createDatabase($connection->database)
                .$this->statements->grantAll($connection->database, $connection->username)
                .$this->statements->createDatabase($connection->test_database)
                .$this->statements->grantAllWithPrefix($connection->test_database, $connection->username),
        );
    }

    /**
     * The active MySQL Database server with this slug, checked before a caller changes anything. A
     * Valkey server holds no Instance databases.
     */
    public function activeServer(string $slug): DatabaseServer
    {
        $server = $this->server($slug);
        $this->assertActive($server);
        $this->assertHoldsDatabases($server);

        return $server;
    }

    /**
     * Create the ACL user `<slug>` on a Valkey server and record the `redis` connection that logs in
     * as it, with the rules that Valkey reports. Orbit adds the reset, the password hash, no
     * channels, and the final deny of `@admin` and `@dangerous`. A create that fails removes the
     * user and records nothing.
     */
    private function createAclUser(DatabaseServer $server, CreateServerDatabaseData $data, ?string $createdBy): DatabaseConnection
    {
        $this->assertActive($server);
        if ($data->database !== null && $data->database >= $this->valkey->databaseCount($server)) {
            throw ValidationException::withMessages(['database' => ['The database index is outside the range configured on this server.']]);
        }
        $slug = $data->slug;

        if (DatabaseConnection::query()->where('slug', $slug)->exists()) {
            throw $this->slugConflict($slug);
        }

        $recorded = DatabaseConnection::query()
            ->where('database_server_id', $server->id)
            ->where('username', $slug)
            ->exists();

        if ($recorded || $this->valkey->userExists($server, $slug)) {
            throw $this->nameConflict($server, $slug);
        }

        $password = Str::random(self::PASSWORD_LENGTH);

        try {
            $rules = $this->valkey->setUser($server, $slug, $password, $data->patterns, $data->allow);
        } catch (ResourceOperationException $exception) {
            $this->undoAclUser($server, $slug);

            throw $exception;
        }

        try {
            $connection = DB::transaction(function () use ($slug, $server, $data, $password, $rules, $createdBy): DatabaseConnection {
                $connection = DatabaseConnection::query()->create([
                    'slug' => $slug,
                    'driver' => DatabaseDriver::Redis->value,
                    'node_id' => $server->node_id,
                    'database_server_id' => $server->id,
                    'owner_instance_id' => null,
                    'host' => $server->node->wireguard_ip,
                    'port' => $server->port,
                    'database' => $data->database === null ? null : (string) $data->database,
                    'test_database' => null,
                    'path' => null,
                    'username' => $slug,
                    'password' => $password,
                ]);

                DatabaseUser::query()->create([
                    'database_connection_id' => $connection->id,
                    'username' => $slug,
                    'password' => $password,
                    'privileges' => $rules,
                    'created_by' => $createdBy,
                ]);

                return $connection;
            });
        } catch (Throwable $exception) {
            $this->undoAclUser($server, $slug);

            throw $exception instanceof UniqueConstraintViolationException ? $this->slugConflict($slug) : $exception;
        }

        $this->broadcaster->broadcast(
            RecordEventType::DatabaseCreated,
            $connection->id,
            DatabaseConnectionData::fromModel($connection)->toArray(),
        );

        return $connection;
    }

    private function server(string $slug): DatabaseServer
    {
        $server = DatabaseServer::query()->with(['node', 'process'])->where('slug', $slug)->first();

        if (! $server instanceof DatabaseServer) {
            throw new ResourceOperationException(
                errorCode: 'database.server_missing',
                message: "Database server [{$slug}] does not exist.",
                status: 404,
            );
        }

        return $server;
    }

    /** A Valkey server holds no databases of its own: each of its users is one `redis` connection. */
    private function assertHoldsDatabases(DatabaseServer $server): void
    {
        if ($server->driver === DatabaseDriver::Redis) {
            throw new ResourceOperationException(
                errorCode: 'database.driver_unsupported',
                message: "Database server [{$server->slug}] is a Valkey server, which holds no Instance databases.",
                status: 422,
            );
        }
    }

    private function assertActive(DatabaseServer $server): void
    {
        if ($server->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                errorCode: 'database.server_inactive',
                message: "Database server [{$server->slug}] is not active. Run database:server:create again to finish it.",
                status: 409,
            );
        }
    }

    /**
     * An Instance has one user per server. A second database for the same Instance reuses that
     * user and its password, so every connection of the Instance keeps working.
     *
     * @return array{username: string, password: string, new: bool}
     */
    private function credentials(DatabaseServer $server, string $database, ?Instance $instance): array
    {
        if ($instance instanceof Instance) {
            $owned = DatabaseConnection::query()
                ->where('database_server_id', $server->id)
                ->where('owner_instance_id', $instance->id)
                ->orderBy('id')
                ->first();

            if (
                $owned instanceof DatabaseConnection
                && is_string($owned->username)
                && is_string($owned->password)
            ) {
                return ['username' => $owned->username, 'password' => $owned->password, 'new' => false];
            }

            return [
                'username' => $this->names->instanceUser($instance->project->slug, $instance->name),
                'password' => Str::random(self::PASSWORD_LENGTH),
                'new' => true,
            ];
        }

        return [
            'username' => $this->names->user($database),
            'password' => Str::random(self::PASSWORD_LENGTH),
            'new' => true,
        ];
    }

    /** @param array{username: string, password: string, new: bool} $credentials */
    private function assertNamesFree(
        DatabaseServer $server,
        string $database,
        ?string $testDatabase,
        #[SensitiveParameter]
        array $credentials,
    ): void {
        $connections = DatabaseConnection::query()->where('database_server_id', $server->id)->get();

        foreach ($connections as $connection) {
            $taken = $connection->database === $database
                || (is_string($connection->test_database) && str_starts_with($database, $connection->test_database))
                || ($testDatabase !== null && is_string($connection->database) && str_starts_with($connection->database, $testDatabase));

            if ($taken) {
                throw $this->nameConflict($server, $database);
            }
        }

        if ($this->admin->execute($server, $this->statements->databasesNamed($database, $testDatabase)) !== []) {
            throw $this->nameConflict($server, $database);
        }

        if (! $credentials['new']) {
            return;
        }

        $username = $credentials['username'];
        $recorded = DatabaseConnection::query()
            ->where('database_server_id', $server->id)
            ->where('username', $username)
            ->exists()
            || DatabaseUser::query()
                ->where('username', $username)
                ->whereIn('database_connection_id', $connections->pluck('id'))
                ->exists();

        if ($recorded || $this->admin->execute($server, $this->statements->userNamed($username)) !== []) {
            throw $this->nameConflict($server, $username);
        }
    }

    /**
     * Remove what a failed create may have left behind. A failure here keeps the original error.
     *
     * @param  array{username: string, password: string, new: bool}  $credentials
     */
    private function undo(
        DatabaseServer $server,
        string $database,
        ?string $testDatabase,
        #[SensitiveParameter]
        array $credentials,
    ): void {
        $sql = $this->statements->dropDatabase($database);

        if ($testDatabase !== null) {
            $sql .= $this->statements->dropDatabase($testDatabase);
        }

        if ($credentials['new']) {
            $sql .= $this->statements->dropUser($credentials['username']);
        }

        try {
            $this->admin->execute($server, $sql);
        } catch (Throwable) {
            // The create already failed; its error is the one to report.
        }
    }

    /** Remove the ACL user that a failed create may have left. A failure here keeps the original error. */
    private function undoAclUser(DatabaseServer $server, string $username): void
    {
        try {
            $this->valkey->deleteUser($server, $username);
        } catch (Throwable) {
            // The create already failed; its error is the one to report.
        }
    }

    private function slugConflict(string $slug): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.slug_conflict',
            message: "Database connection [{$slug}] already exists.",
            status: 409,
        );
    }

    private function nameConflict(DatabaseServer $server, string $name): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.name_conflict',
            message: "Database server [{$server->slug}] already has a database or user named [{$name}].",
            status: 409,
        );
    }
}
