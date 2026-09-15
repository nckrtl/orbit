<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\QueryDatabaseConnectionRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseQueryResponse;

final class QueryDatabaseConnectionCommand extends DatabaseCommand
{
    #[\Override]
    protected $signature = 'database:query
        {slug : Database connection slug}
        {sql : SQL statement}
        {--write : Allow a write statement}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Run SQL against a registered Database connection.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $slug = $this->slug();
        $sql = $this->stringArgument('sql', 'SQL statement', 'database.sql_required');

        if ($slug === null || $sql === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $result = $this->send(
            $connector,
            new QueryDatabaseConnectionRequest($slug, $sql, $this->option('write') === true),
            DatabaseQueryResponse::class,
        );

        if (! $result instanceof DatabaseQueryResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($result->toArray());

            return self::SUCCESS;
        }

        $headers = $result->columns === [] ? ['Result'] : $result->columns;
        $rows = [];

        foreach ($result->rows as $row) {
            if ($result->columns === []) {
                $rows[] = ['ok'];

                continue;
            }

            $cells = [];

            foreach ($result->columns as $column) {
                $cells[] = $this->displayValue($row[$column] ?? null);
            }

            $rows[] = $cells;
        }

        return $this->renderInspectionTable(
            "Database query [{$result->slug}].",
            $headers,
            $rows,
            $result->requestId,
        );
    }
}
