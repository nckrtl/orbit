<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseConnectionsRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionsResponse;

final class ListDatabaseConnectionsCommand extends DatabaseCommand
{
    #[\Override]
    protected $signature = 'database:list
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List registered Database connections.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListDatabaseConnectionsRequest,
            DatabaseConnectionsResponse::class,
            ['List Database connections', 'Loading Database connections', 'Loaded Database connections'],
        );

        if (! $response instanceof DatabaseConnectionsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->connections as $connection) {
            $rows[] = [
                $connection->slug,
                $connection->driver,
                $connection->host ?? $connection->path,
                $connection->database,
                $connection->hasPassword ? 'stored' : null,
            ];
        }

        if ($rows === []) {
            $this->writeHumanMessage('No Database connections.');
            $this->writeHumanMessage("Request ID: {$response->requestId}");

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Slug', 'Driver', 'Endpoint', 'Database', 'Password'],
            $rows,
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
