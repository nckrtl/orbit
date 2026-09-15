<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseTablesRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseTablesResponse;

final class ListDatabaseTablesCommand extends DatabaseCommand
{
    #[\Override]
    protected $signature = 'database:tables
        {slug : Database connection slug}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List tables on a registered Database connection.';

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

        $result = $this->send(
            $connector,
            new ListDatabaseTablesRequest($slug),
            DatabaseTablesResponse::class,
        );

        if (! $result instanceof DatabaseTablesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($result->toArray());

            return self::SUCCESS;
        }

        return $this->renderInspectionTable(
            "Database tables [{$result->slug}].",
            ['Table'],
            array_map(static fn (string $table): array => [$table], $result->tables),
            $result->requestId,
        );
    }
}
