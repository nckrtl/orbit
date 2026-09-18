<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

use App\Support\Tui\Sources\Concerns\LimitsBackgroundRequestTime;
use Closure;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseUsersRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseUserResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseUsersResponse;

/** The users recorded on one Database connection, from `GET /database-connections/{slug}/users`. */
final readonly class GatewayDatabaseUsersSource implements DatabaseUsersSource
{
    use LimitsBackgroundRequestTime;

    /** @param  Closure(object, string): object  $send  Same shape as GatewayCommand::sendOrThrow(). */
    public function __construct(private Closure $send) {}

    #[\Override]
    public function forConnection(string $slug): ?array
    {
        try {
            $response = ($this->send)(self::withBackgroundTimeout(new ListDatabaseUsersRequest($slug)), DatabaseUsersResponse::class);
        } catch (GatewayApiException) {
            return null;
        }

        assert($response instanceof DatabaseUsersResponse);

        return array_map(self::row(...), $response->users);
    }

    /** @return array{username: string, privileges: string, created_by: string} */
    private static function row(DatabaseUserResponse $user): array
    {
        return [
            'username' => $user->username,
            'privileges' => $user->privileges,
            'created_by' => $user->createdBy ?? '—',
        ];
    }
}
