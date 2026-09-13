<?php

declare(strict_types=1);

namespace App\Actions\Herdr;

use App\Models\HerdrSession;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListHerdrSessionsAction
{
    /** @return Collection<int, HerdrSession> */
    public function execute(int $nodeId): Collection
    {
        return HerdrSession::query()
            ->with(['node', 'process'])
            ->where('node_id', $nodeId)
            ->orderBy('session')
            ->orderBy('id')
            ->get();
    }
}
