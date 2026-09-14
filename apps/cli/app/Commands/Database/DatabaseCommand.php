<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Commands\GatewayCommand;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;

abstract class DatabaseCommand extends GatewayCommand
{
    public const string SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

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
}
