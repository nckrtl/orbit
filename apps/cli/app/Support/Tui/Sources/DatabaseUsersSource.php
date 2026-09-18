<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

/**
 * The users recorded on one Database connection, as `orbit top`'s Users pane lists them.
 *
 * `Sources\GatewayDatabaseUsersSource` calls `GET /database-connections/{slug}/users`.
 * `forConnection()` returns `null` when the Gateway request fails (an older Gateway that does
 * not expose per-connection users, for example), and the pane renders "Not available on this
 * Gateway yet." instead of a table.
 */
interface DatabaseUsersSource
{
    /**
     * @return list<array{username: string, privileges: string, created_by: string}>|null Null when
     *                                                                                    this Gateway cannot list users for this connection.
     */
    public function forConnection(string $slug): ?array;
}
