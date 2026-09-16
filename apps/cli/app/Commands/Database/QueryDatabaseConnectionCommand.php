<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
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

        $result = $this->sendWithProgress(
            $connector,
            new QueryDatabaseConnectionRequest($slug, $sql, $this->option('write') === true),
            DatabaseQueryResponse::class,
            ['Run Database query', 'Running Database query', 'Ran Database query'],
        );

        if (! $result instanceof DatabaseQueryResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($result->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Database query [{$result->slug}].", [
            'Write permission' => $result->write ? 'yes' : 'no',
            'Reported row count' => $result->rowCount,
            'Truncated' => $result->truncated ? 'yes' : 'no',
            'Request ID' => $result->requestId,
        ]));

        if ($result->columns === [] && $result->rows === []) {
            $this->writeHumanMessage(
                $result->write ? 'Statement completed.' : 'No matching records found.',
            );
            $this->writeTruncationWarning($result->truncated);

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
                $cells[] = $this->queryCell($row[$column] ?? null);
            }

            $rows[] = $cells;
        }

        ConsoleWriter::write(
            $this->output,
            $this->humanRenderer()->table($headers, $rows, 'No matching records found.'),
        );
        $this->writeTruncationWarning($result->truncated);

        return self::SUCCESS;
    }

    private function writeTruncationWarning(bool $truncated): void
    {
        if (! $truncated) {
            return;
        }

        $this->writeHumanMessage('Result truncated at the Gateway row limit. The omitted total is not known.');
    }

    private function queryCell(bool|float|int|string|null $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($value === '') {
            return '""';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
