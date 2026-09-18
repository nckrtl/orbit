<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

use Closure;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseUsersRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseUserResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseUsersResponse;

/**
 * The users recorded on one Database connection, from
 * `GET /database-connections/{slug}/users`.
 *
 * The Gateway response has no field naming what uses a user, so "Used by" reports who or what
 * created it (`created_by`) until the Gateway records that relationship.
 */
final readonly class GatewayDatabaseUsersSource implements DatabaseUsersSource
{
    /** @param  Closure(object, string): object  $send  Same shape as GatewayCommand::sendOrThrow(). */
    public function __construct(private Closure $send) {}

    #[\Override]
    public function forConnection(string $slug): ?array
    {
        try {
            $response = ($this->send)(new ListDatabaseUsersRequest($slug), DatabaseUsersResponse::class);
        } catch (GatewayApiException) {
            return null;
        }

        assert($response instanceof DatabaseUsersResponse);

        return array_map(self::row(...), $response->users);
    }

    /** @return array{username: string, privileges: string, used_by: string} */
    private static function row(DatabaseUserResponse $user): array
    {
        return [
            'username' => $user->username,
            'privileges' => $user->privileges,
            'used_by' => $user->createdBy ?? '—',
        ];
    }
}
