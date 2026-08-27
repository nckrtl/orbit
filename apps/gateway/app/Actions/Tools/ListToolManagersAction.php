<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\ToolManagerRecord;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListToolManagersAction
{
    /** @return Collection<int, ToolManagerRecord> */
    public function execute(int $nodeId): Collection
    {
        return ToolManagerRecord::query()
            ->where('node_id', $nodeId)
            ->orderBy('name')
            ->get();
    }
}
