<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseServers\ShowDatabaseServerRequest;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServerResponse;

final class ShowDatabaseServerCommand extends DatabaseCommand
{
    #[\Override]
    protected $signature = 'database:server:show
        {slug : Database server slug}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one Database server and the databases on it.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->serverSlug();

        if ($slug === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $server = $this->sendWithProgress(
            $connector,
            new ShowDatabaseServerRequest($slug),
            DatabaseServerResponse::class,
            ['Show Database server', 'Loading Database server', 'Loaded Database server'],
        );

        if (! $server instanceof DatabaseServerResponse) {
            return self::FAILURE;
        }

        return $this->renderServer($server, "Database server [{$server->slug}].");
    }
}
