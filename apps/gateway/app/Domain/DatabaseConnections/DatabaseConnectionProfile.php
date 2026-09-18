<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use App\Data\DatabaseConnections\AddDatabaseConnectionData;
use App\Data\DatabaseConnections\UpdateDatabaseConnectionData;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;

final readonly class DatabaseConnectionProfile
{
    public function __construct(
        public string $slug,
        public DatabaseDriver $driver,
        public ?int $nodeId,
        public ?string $host,
        public ?int $port,
        public ?string $database,
        public ?string $path,
        public ?string $username,
        public ?string $password,
    ) {}

    public static function fromAdd(AddDatabaseConnectionData $data): self
    {
        return self::normalized(
            slug: $data->slug,
            driver: $data->driver,
            nodeId: $data->nodeId,
            host: $data->host,
            port: $data->port,
            database: $data->database,
            path: $data->path,
            username: $data->username,
            password: $data->password,
            hostProvided: $data->host !== null,
            portProvided: $data->port !== null,
            databaseProvided: $data->database !== null,
            pathProvided: $data->path !== null,
            passwordRequired: $data->driver->requiresCredentials(),
        );
    }

    public static function fromUpdate(DatabaseConnection $connection, UpdateDatabaseConnectionData $data): self
    {
        $driver = $data->driverProvided && $data->driver instanceof DatabaseDriver
            ? $data->driver
            : $connection->driver;

        return self::normalized(
            slug: $connection->slug,
            driver: $driver,
            nodeId: $data->nodeIdProvided ? $data->nodeId : $connection->node_id,
            host: $data->hostProvided ? $data->host : $connection->host,
            port: $data->portProvided ? $data->port : $connection->port,
            database: $data->databaseProvided ? $data->database : $connection->database,
            path: $data->pathProvided ? $data->path : $connection->path,
            username: $data->usernameProvided ? $data->username : $connection->username,
            password: $data->passwordProvided ? $data->password : $connection->password,
            hostProvided: $data->hostProvided,
            portProvided: $data->portProvided,
            databaseProvided: $data->databaseProvided,
            pathProvided: $data->pathProvided,
            passwordRequired: $driver->requiresCredentials() && $connection->password === null && ! $data->passwordProvided,
        );
    }

    /** @return array<string, int|string|null> */
    public function attributes(): array
    {
        return [
            'slug' => $this->slug,
            'driver' => $this->driver->value,
            'node_id' => $this->nodeId,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'path' => $this->path,
            'username' => $this->username,
            'password' => $this->password,
        ];
    }

    private static function normalized(
        string $slug,
        DatabaseDriver $driver,
        ?int $nodeId,
        ?string $host,
        ?int $port,
        ?string $database,
        ?string $path,
        ?string $username,
        ?string $password,
        bool $hostProvided,
        bool $portProvided,
        bool $databaseProvided,
        bool $pathProvided,
        bool $passwordRequired,
    ): self {
        if ($driver->usesNetworkEndpoint()) {
            if ($pathProvided && $path !== null) {
                self::fail('A mysql, pgsql, or redis connection does not accept a sqlite path.');
            }

            if ($driver->requiresCredentials()) {
                if ($host === null || $database === null || $username === null) {
                    self::fail('A mysql or pgsql connection requires host, database, and username.');
                }

                if ($passwordRequired && $password === null) {
                    self::fail('A mysql or pgsql connection requires a password.');
                }
            } elseif ($host === null) {
                self::fail('A redis connection requires a host.');
            }

            $profile = new self(
                slug: $slug,
                driver: $driver,
                nodeId: $nodeId,
                host: $host,
                port: $port ?? $driver->defaultPort(),
                database: $database,
                path: null,
                username: $username,
                password: $password,
            );
        } else {
            if (($hostProvided && $host !== null) || ($portProvided && $port !== null) || ($databaseProvided && $database !== null)) {
                self::fail('A sqlite connection does not accept host, port, or database.');
            }

            if ($path === null) {
                self::fail('A sqlite connection requires a Unix absolute path.');
            }

            $profile = new self(
                slug: $slug,
                driver: $driver,
                nodeId: $nodeId,
                host: null,
                port: null,
                database: null,
                path: $path,
                username: $username,
                password: $password,
            );
        }

        return $profile;
    }

    private static function fail(string $message): never
    {
        throw new ResourceOperationException(
            errorCode: 'validation.failed',
            message: $message,
            status: 422,
        );
    }
}
