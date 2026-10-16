<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseConnectionRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;

final class CreateDatabaseConnectionCommand extends DatabaseCommand
{
    #[\Override]
    protected $signature = 'database:create
        {slug : Database connection slug}
        {--driver= : Driver: mysql, pgsql, sqlite, or redis}
        {--node= : Optional Node ID or registered name}
        {--host= : Hostname or IP for mysql and pgsql}
        {--port= : TCP port for mysql and pgsql}
        {--database= : Database name for mysql and pgsql, or index for redis}
        {--path= : Unix absolute sqlite path}
        {--username= : Username}
        {--password= : Password}
        {--server= : Database server slug. Creates a MySQL database or Valkey ACL user on that server}
        {--instance= : Numeric Instance ID that owns a database created with --server}
        {--pattern=* : Key pattern for a Valkey ACL user with --server}
        {--allow=* : Allowed command or category for a Valkey ACL user with --server}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Register an existing database, or create one on a Database server.';

    /** @var list<string> */
    private const array REGISTRATION_OPTIONS = ['driver', 'node', 'host', 'port', 'path', 'username', 'password'];

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();
        $driver = $this->option('driver');

        if ($slug === null) {
            return self::FAILURE;
        }

        $server = $this->stringOption('server');

        if ($server !== null) {
            return $this->createOnServer($repository, $connectors, $slug, $server);
        }

        if ($this->option('pattern') !== [] || $this->option('allow') !== []) {
            return $this->renderGatewayFailure('database.server_required', 'The --pattern and --allow options require --server.');
        }

        if ($this->option('instance') !== null) {
            return $this->renderGatewayFailure('database.server_required', 'The --instance option requires --server.');
        }

        if (! is_string($driver) || ! in_array($driver, self::DRIVERS, true)) {
            return $this->renderGatewayFailure(
                'database.driver_invalid',
                'Driver must be mysql, pgsql, sqlite, or redis.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $node = $this->option('node');
        $nodeId = null;

        if (is_string($node) && $node !== '') {
            $nodeId = $this->resolveNodeId($connector, $node);

            if ($nodeId === null) {
                return self::FAILURE;
            }
        }

        $port = $this->option('port');
        $parsedPort = null;

        if ($port !== null) {
            $parsedPort = filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

            if (! is_int($parsedPort)) {
                return $this->renderGatewayFailure('database.port_invalid', 'Port must be an integer from 1 through 65535.');
            }
        }

        $hasPassword = $this->input->getOption('password') !== null;

        if (in_array($driver, ['mysql', 'pgsql'], true) && ! $hasPassword) {
            return $this->renderGatewayFailure(
                'database.password_required',
                'A mysql or pgsql connection requires --password.',
            );
        }

        $password = $this->input->getOption('password');

        $connection = $this->sendWithProgress(
            $connector,
            new CreateDatabaseConnectionRequest(
                slug: $slug,
                driver: $driver,
                nodeId: $nodeId,
                host: $this->stringOption('host'),
                port: $parsedPort,
                database: $this->stringOption('database'),
                path: $this->stringOption('path'),
                username: $this->stringOption('username'),
                password: is_string($password) ? $password : null,
                hasPassword: $hasPassword,
            ),
            DatabaseConnectionResponse::class,
            ['Create Database connection', 'Creating Database connection', 'Created Database connection'],
        );

        if (! $connection instanceof DatabaseConnectionResponse) {
            return self::FAILURE;
        }

        return $this->renderConnection($connection, "Database connection [{$connection->slug}] created.");
    }

    private function createOnServer(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
        string $slug,
        string $server,
    ): int {
        foreach (self::REGISTRATION_OPTIONS as $option) {
            if ($this->input->getOption($option) !== null) {
                return $this->renderGatewayFailure(
                    'database.server_options_conflict',
                    "The --server option excludes --{$option}. The Gateway derives the connection from the server.",
                );
            }
        }

        if (strlen($server) > 63 || preg_match(self::SLUG_PATTERN, $server) !== 1) {
            return $this->renderGatewayFailure('database.server_slug_invalid', 'Database server slug is invalid.');
        }

        $instance = $this->option('instance');
        $instanceId = null;

        if ($instance !== null) {
            $instanceId = filter_var($instance, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (! is_int($instanceId)) {
                return $this->renderGatewayFailure('database.instance_invalid', 'Instance ID must be a positive integer.');
            }
        }

        $database = $this->stringOption('database');
        $databaseIndex = null;
        if ($database !== null) {
            $databaseIndex = filter_var($database, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999]]);
            if (! is_int($databaseIndex)) {
                return $this->renderGatewayFailure('database.database_invalid', 'A server database index must be an integer from 0 through 999.');
            }
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $connection = $this->sendWithProgress(
            $connector,
            new CreateDatabaseConnectionRequest(
                slug: $slug,
                server: $server,
                instanceId: $instanceId,
                database: $databaseIndex,
                patterns: $this->aclOptions('pattern'),
                allow: $this->aclOptions('allow'),
            ),
            DatabaseConnectionResponse::class,
            ['Create database', 'Creating database', 'Created database'],
        );

        if (! $connection instanceof DatabaseConnectionResponse) {
            return self::FAILURE;
        }

        $message = $connection->driver === 'redis'
            ? "ACL user [{$connection->username}] created on [{$server}]."
            : "Database [{$connection->database}] created on [{$server}].";

        return $this->renderConnection($connection, $message);
    }

    /** @return list<string>|null */
    private function aclOptions(string $name): ?array
    {
        $values = $this->option($name);
        if (! is_array($values) || $values === []) {
            return null;
        }

        return array_values(array_map(static fn (?string $value): string => $value ?? '', $values));
    }
}
