<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\DestroyDatabaseConnectionRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;

final class DestroyDatabaseConnectionCommand extends DatabaseCommand
{
    #[\Override]
    protected $signature = 'database:destroy
        {slug : Database connection slug}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy a Database connection.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();

        if ($slug === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if (! $this->confirmAction(
            "Destroy Database connection record [{$slug}]? The physical database is not dropped.",
            'Database connection destruction cancelled.',
            option: 'force',
            requiredCode: 'database.confirmation_required',
            requiredMessage: 'Use --force to confirm Database connection destruction.',
        )) {
            return self::FAILURE;
        }

        $connection = $this->sendWithProgress(
            $connector,
            new DestroyDatabaseConnectionRequest($slug),
            DatabaseConnectionResponse::class,
            ['Destroy Database connection', 'Destroying Database connection', 'Destroyed Database connection'],
        );

        if (! $connection instanceof DatabaseConnectionResponse) {
            return self::FAILURE;
        }

        return $this->renderConnection($connection, "Database connection [{$connection->slug}] destroyed.");
    }
}
