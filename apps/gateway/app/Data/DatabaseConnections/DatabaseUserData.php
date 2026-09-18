<?php

declare(strict_types=1);

namespace App\Data\DatabaseConnections;

use App\Models\DatabaseUser;
use DateTimeInterface;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DatabaseUserData extends Data
{
    public function __construct(
        public int $id,
        public int $databaseConnectionId,
        public string $username,
        public string $privileges,
        public ?string $createdBy,
        public string $createdAt,
    ) {}

    public static function fromModel(DatabaseUser $user): self
    {
        return new self(
            id: $user->id,
            databaseConnectionId: $user->database_connection_id,
            username: $user->username,
            privileges: $user->privileges,
            createdBy: $user->created_by,
            createdAt: $user->created_at?->format(DateTimeInterface::ATOM) ?? '',
        );
    }
}
