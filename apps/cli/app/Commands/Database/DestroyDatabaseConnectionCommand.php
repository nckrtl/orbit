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

        if (! $this->confirmed()) {
            return self::FAILURE;
        }

        $connection = $this->send(
            $connector,
            new DestroyDatabaseConnectionRequest($slug),
            DatabaseConnectionResponse::class,
        );

        if (! $connection instanceof DatabaseConnectionResponse) {
            return self::FAILURE;
        }

        return $this->renderConnection($connection, "Database connection [{$connection->slug}] destroyed.");
    }

    private function confirmed(): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        if ($this->option('json') !== true && $this->input->isInteractive()) {
            return $this->confirm('Confirm Database connection destruction?', false);
        }

        $this->renderGatewayFailure(
            'database.confirmation_required',
            'Use --force to confirm Database connection destruction.',
        );

        return false;
    }
}
