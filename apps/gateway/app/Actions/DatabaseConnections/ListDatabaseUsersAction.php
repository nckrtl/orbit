<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Models\DatabaseConnection;
use App\Models\DatabaseUser;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListDatabaseUsersAction
{
    /** @return Collection<int, DatabaseUser> */
    public function execute(DatabaseConnection $connection): Collection
    {
        return $connection->users()->orderBy('username')->get();
    }
}
