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
        {--driver= : Driver: mysql, pgsql, or sqlite}
        {--node= : Optional Node ID or registered name}
        {--host= : Hostname or IP for mysql and pgsql}
        {--port= : TCP port for mysql and pgsql}
        {--database= : Database name for mysql and pgsql}
        {--path= : Unix absolute sqlite path}
        {--username= : Username}
        {--password= : Password}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a Database connection through the Gateway.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();
        $driver = $this->option('driver');

        if ($slug === null) {
            return self::FAILURE;
        }

        if (! is_string($driver) || ! in_array($driver, self::DRIVERS, true)) {
            return $this->renderGatewayFailure(
                'database.driver_invalid',
                'Driver must be mysql, pgsql, or sqlite.',
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

        $connection = $this->send(
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
        );

        if (! $connection instanceof DatabaseConnectionResponse) {
            return self::FAILURE;
        }

        return $this->renderConnection($connection, "Database connection [{$connection->slug}] created.");
    }
}
