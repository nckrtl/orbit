<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseUserRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;

final class CreateDatabaseUserCommand extends DatabaseCommand
{
    #[\Override]
    protected $signature = 'database:user:create
        {slug : Database connection slug}
        {--process= : Numeric Node-targeted Docker MySQL Process ID}
        {--database= : Database name to create}
        {--username= : Username to create}
        {--password= : Password for the created user}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create a MySQL user and database through a Node Docker Process and register the connection.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();

        if ($slug === null) {
            return self::FAILURE;
        }

        $process = $this->option('process');
        $processId = filter_var($process, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($processId)) {
            return $this->renderGatewayFailure(
                'database.process_invalid',
                'Process ID must be a positive integer.',
            );
        }

        $database = $this->stringOption('database');
        $username = $this->stringOption('username');
        $password = $this->input->getOption('password');

        if ($database === null) {
            return $this->renderGatewayFailure('database.database_required', 'A managed MySQL user requires --database.');
        }

        if ($username === null) {
            return $this->renderGatewayFailure('database.username_required', 'A managed MySQL user requires --username.');
        }

        if (! is_string($password) || $password === '') {
            return $this->renderGatewayFailure('database.password_required', 'A managed MySQL user requires --password.');
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $connection = $this->sendWithProgress(
            $connector,
            new CreateDatabaseUserRequest(
                processId: $processId,
                slug: $slug,
                database: $database,
                username: $username,
                password: $password,
            ),
            DatabaseConnectionResponse::class,
            ['Create Database user', 'Creating Database user', 'Created Database user'],
        );

        if (! $connection instanceof DatabaseConnectionResponse) {
            return self::FAILURE;
        }

        return $this->renderConnection($connection, "Database connection [{$connection->slug}] registered.");
    }
}
