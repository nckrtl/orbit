<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Models\DatabaseServer;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListDatabaseServersAction
{
    /** @return Collection<int, DatabaseServer> */
    public function execute(): Collection
    {
        return DatabaseServer::query()
            ->orderBy('slug')
            ->get();
    }
}
