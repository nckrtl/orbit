<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseServers\DestroyDatabaseServerRequest;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServerResponse;

final class DestroyDatabaseServerCommand extends DatabaseCommand
{
    #[\Override]
    protected $signature = 'database:server:destroy
        {slug : Database server slug}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a Database server that no connection uses. Its data volume stays on the Node.';

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

        if (! $this->confirmAction(
            "Destroy Database server [{$slug}] and its Process? The data volume stays on the Node.",
            'Database server destruction cancelled.',
            option: 'force',
            requiredCode: 'database.confirmation_required',
            requiredMessage: 'Use --force to confirm Database server destruction.',
        )) {
            return self::FAILURE;
        }

        $server = $this->sendWithProgress(
            $connector,
            new DestroyDatabaseServerRequest($slug),
            DatabaseServerResponse::class,
            ['Destroy Database server', 'Destroying Database server', 'Destroyed Database server'],
        );

        if (! $server instanceof DatabaseServerResponse) {
            return self::FAILURE;
        }

        return $this->renderServer($server, "Database server [{$server->slug}] destroyed.");
    }
}
