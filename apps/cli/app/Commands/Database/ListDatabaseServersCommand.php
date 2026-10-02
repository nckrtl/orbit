<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\DatabaseServers\ListDatabaseServersRequest;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServerResponse;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServersResponse;

final class ListDatabaseServersCommand extends DatabaseCommand
{
    #[\Override]
    protected $signature = 'database:server:list
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List the Database servers.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListDatabaseServersRequest,
            DatabaseServersResponse::class,
            ['List Database servers', 'Loading Database servers', 'Loaded Database servers'],
        );

        if (! $response instanceof DatabaseServersResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Slug', 'Node ID', 'Tag', 'Port', 'Status', 'Databases'],
            array_map(
                static fn (DatabaseServerResponse $server): array => [
                    $server->slug,
                    $server->nodeId,
                    $server->tag,
                    $server->port,
                    $server->status,
                    $server->databasesCount,
                ],
                $response->servers,
            ),
            'No Database servers.',
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
