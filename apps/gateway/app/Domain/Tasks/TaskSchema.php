<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;

/**
 * The merged tasks table has parent_id. Historical migration tests still open the earlier
 * task_groups table, so the models follow whichever schema the connection has.
 */
final class TaskSchema
{
    /** @var array<string, bool> */
    private static array $merged = [];

    public static function merged(Connection $connection): bool
    {
        $key = $connection->getName().'|'.(string) $connection->getDatabaseName();

        if (! array_key_exists($key, self::$merged)) {
            self::$merged[$key] = Schema::connection($connection->getName())->hasColumn('tasks', 'parent_id');
        }

        return self::$merged[$key];
    }
}
