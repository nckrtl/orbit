<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\RemoveDatabaseConnectionRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;

final class RemoveDatabaseConnectionCommand extends DatabaseCommand
{
    #[\Override]
    protected $signature = 'database:remove
        {slug : Database connection slug}
        {--force : Skip the destructive confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a Database connection.';

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
            new RemoveDatabaseConnectionRequest($slug),
            DatabaseConnectionResponse::class,
        );

        if (! $connection instanceof DatabaseConnectionResponse) {
            return self::FAILURE;
        }

        return $this->renderConnection($connection, "Database connection [{$connection->slug}] removed.");
    }

    private function confirmed(): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        if ($this->option('json') !== true && $this->input->isInteractive()) {
            return $this->confirm('Confirm Database connection removal?', false);
        }

        $this->renderGatewayFailure(
            'database.confirmation_required',
            'Use --force to confirm Database connection removal.',
        );

        return false;
    }
}
