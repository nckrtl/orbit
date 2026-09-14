<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Models\DatabaseConnection;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListDatabaseConnectionsAction
{
    /** @return Collection<int, DatabaseConnection> */
    public function execute(): Collection
    {
        return DatabaseConnection::query()
            ->orderBy('slug')
            ->get();
    }
}
