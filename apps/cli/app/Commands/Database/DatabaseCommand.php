<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServerResponse;

abstract class DatabaseCommand extends GatewayCommand
{
    public const string SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    public const string PREFIX_PATTERN = '/\A[A-Z][A-Z0-9_]{0,31}\z/D';

    /** @var list<string> */
    protected const array DRIVERS = ['mysql', 'pgsql', 'sqlite', 'redis'];

    protected function slug(): ?string
    {
        $slug = $this->stringArgument('slug', 'Database connection slug', 'database.slug_required');

        if ($slug === null) {
            return null;
        }

        if (strlen($slug) > 63 || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            $this->renderGatewayFailure('database.slug_invalid', 'Database connection slug is invalid.');

            return null;
        }

        return $slug;
    }

    protected function serverSlug(): ?string
    {
        $slug = $this->stringArgument('slug', 'Database server slug', 'database.server_slug_required');

        if ($slug === null) {
            return null;
        }

        if (strlen($slug) > 63 || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            $this->renderGatewayFailure('database.server_slug_invalid', 'Database server slug is invalid.');

            return null;
        }

        return $slug;
    }

    protected function renderConnection(DatabaseConnectionResponse $connection, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($connection->toArray());

            return self::SUCCESS;
        }

        $fields = [
            'ID' => $connection->id,
            'Slug' => $connection->slug,
            'Driver' => $connection->driver,
            'Node ID' => $connection->nodeId,
            'Host' => $connection->host,
            'Port' => $connection->port,
            'Database' => $connection->database,
            'Path' => $connection->path,
            'Username' => $connection->username,
            'Password' => $connection->hasPassword ? 'stored' : null,
        ];

        if ($connection->server !== null) {
            $fields['Server'] = $connection->server;
            $fields['Owner Instance ID'] = $connection->ownerInstanceId;
            $fields['Test database'] = $connection->testDatabase;
        }

        if ($connection->usersCount !== null) {
            $fields['Users'] = $connection->usersCount;
        }

        $fields['Request ID'] = $connection->requestId;

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($message, $fields));

        return self::SUCCESS;
    }

    protected function renderServer(DatabaseServerResponse $server, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($server->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($message, [
            'ID' => $server->id,
            'Slug' => $server->slug,
            'Node ID' => $server->nodeId,
            'Process ID' => $server->processId,
            'Tag' => $server->tag,
            'Port' => $server->port,
            'Status' => $server->status,
            'Databases' => $server->databasesCount,
            'Request ID' => $server->requestId,
        ]));

        if ($server->databases !== null && $server->databases !== []) {
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(
                ['Slug', 'Database', 'Username', 'Owner Instance ID'],
                array_map(
                    static fn (DatabaseConnectionResponse $connection): array => [
                        $connection->slug,
                        $connection->database,
                        $connection->username,
                        $connection->ownerInstanceId,
                    ],
                    $server->databases,
                ),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<bool|int|string|null>>  $rows
     */
    protected function renderInspectionTable(string $message, array $headers, array $rows, string $requestId): int
    {
        if ($this->option('json') === true) {
            return self::SUCCESS;
        }

        $this->writeHumanMessage($message);
        ConsoleWriter::write(
            $this->output,
            $this->humanRenderer()->table($headers, $rows, 'No matching records found.'),
        );
        $this->writeHumanMessage("Request ID: {$requestId}");

        return self::SUCCESS;
    }

    protected function displayValue(bool|float|int|string|null $value): string
    {
        if ($value === null) {
            return '—';
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
