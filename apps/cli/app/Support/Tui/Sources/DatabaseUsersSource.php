<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

/**
 * The users or roles recorded on one Database connection, as `orbit top`'s Users pane would
 * list them.
 *
 * The Gateway does not yet expose `GET /database-connections/{slug}/users`; a parallel slice is
 * adding it. `forConnection()` returns `null` until then, and the pane renders "Not available on
 * this Gateway yet." instead of a table. Wire the real SDK request by replacing
 * `NullDatabaseUsersSource` with an implementation that calls the new request and maps its
 * response into the same row shape.
 */
interface DatabaseUsersSource
{
    /**
     * @return list<array{username: string, privileges: string, used_by: string}>|null Null when
     *                                                                                 this Gateway cannot list users for this connection yet.
     */
    public function forConnection(string $slug): ?array;
}
