<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\GatewayCommand;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;

abstract class DatabaseCommand extends GatewayCommand
{
    public const string SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    public const string PREFIX_PATTERN = '/\A[A-Z][A-Z0-9_]{0,31}\z/D';

    /** @var list<string> */
    protected const array DRIVERS = ['mysql', 'pgsql', 'sqlite'];

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

    protected function renderConnection(DatabaseConnectionResponse $connection, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($connection->toArray());

            return self::SUCCESS;
        }

        $this->info($message);
        $this->table(['Field', 'Value'], [
            ['ID', $connection->id],
            ['Slug', $connection->slug],
            ['Driver', $connection->driver],
            ['Node ID', $connection->nodeId ?? '—'],
            ['Host', $connection->host ?? '—'],
            ['Port', $connection->port ?? '—'],
            ['Database', $connection->database ?? '—'],
            ['Path', $connection->path ?? '—'],
            ['Username', $connection->username ?? '—'],
            ['Password', $connection->hasPassword ? 'stored' : '—'],
            ['Request ID', $connection->requestId],
        ]);

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

        $this->info($message);

        if ($rows === []) {
            $this->line('No matching records were found.');
        } else {
            $this->table($headers, $rows);
        }

        $this->line("Request ID: {$requestId}");

        return self::SUCCESS;
    }

    protected function displayValue(bool|float|int|string|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
