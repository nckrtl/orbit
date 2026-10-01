<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Models\DatabaseServer;

/**
 * Runs admin commands as MySQL root inside a Database server's container. The root password
 * travels only on protected standard input. A failed command throws
 * `database.server_command_failed` without SQL output.
 */
interface DatabaseServerAdmin
{
    /** Wait until MySQL accepts the root login over TCP inside the container. */
    public function waitUntilReady(DatabaseServer $server, int $timeoutSeconds): bool;

    /**
     * Run SQL statements and return the first column of each result row.
     *
     * @return list<string>
     */
    public function execute(DatabaseServer $server, string $sql): array;

    /** Copy one database into another, empty one on the same server with mysqldump piped into mysql. */
    public function copyDatabase(DatabaseServer $server, string $source, string $target): void;
}
