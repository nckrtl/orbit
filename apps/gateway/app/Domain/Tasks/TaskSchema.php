<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;

/**
 * Historical migration tests still open the tasks table from before parent_id existed.
 * The model follows whichever shape the connection has.
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
