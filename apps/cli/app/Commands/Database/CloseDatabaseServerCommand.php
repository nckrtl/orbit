<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseServers\CloseDatabaseServerRequest;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServerResponse;

final class CloseDatabaseServerCommand extends DatabaseCommand
{
    #[\Override]
    protected $signature = 'database:server:close
        {slug : Database server slug}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Give default the admin password on an adopted Valkey server.';

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
            new CloseDatabaseServerRequest($slug),
            DatabaseServerResponse::class,
            ['Close Database server', 'Closing Database server', 'Closed Database server'],
        );

        if (! $server instanceof DatabaseServerResponse) {
            return self::FAILURE;
        }

        return $this->renderServer($server, "Database server [{$server->slug}] is closed.");
    }
}
