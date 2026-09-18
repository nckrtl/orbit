<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\DatabaseConnections;

final readonly class DatabaseUsersResponse
{
    /** @param list<DatabaseUserResponse> $users */
    public function __construct(
        public array $users,
        public string $requestId,
    ) {}

    /** @return array{users: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'users' => array_map(
                static fn (DatabaseUserResponse $user): array => $user->toArray(),
                $this->users,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
