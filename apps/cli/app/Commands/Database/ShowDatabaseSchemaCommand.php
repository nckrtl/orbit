<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\DatabaseConnections\ShowDatabaseSchemaRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseSchemaResponse;

final class ShowDatabaseSchemaCommand extends DatabaseCommand
{
    #[\Override]
    protected $signature = 'database:schema
        {slug : Database connection slug}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show schema for a registered Database connection.';

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

        $result = $this->sendWithProgress(
            $connector,
            new ShowDatabaseSchemaRequest($slug),
            DatabaseSchemaResponse::class,
            ['Show Database schema', 'Loading Database schema', 'Loaded Database schema'],
        );

        if (! $result instanceof DatabaseSchemaResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($result->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($result->tables as $table) {
            foreach ($table['columns'] as $column) {
                $rows[] = [
                    $table['name'],
                    $column['name'],
                    $column['type'],
                    $column['nullable'] ? 'yes' : 'no',
                    $this->displayValue($column['default']),
                    $column['primary'] ? 'yes' : 'no',
                ];
            }
        }

        return $this->renderInspectionTable(
            "Database schema [{$result->slug}].",
            ['Table', 'Column', 'Type', 'Nullable', 'Default', 'Primary'],
            $rows,
            $result->requestId,
        );
    }
}
