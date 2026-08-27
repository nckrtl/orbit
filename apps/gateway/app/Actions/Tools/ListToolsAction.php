<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\Tool;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListToolsAction
{
    /** @return Collection<int, Tool> */
    public function execute(int $nodeId): Collection
    {
        return Tool::query()
            ->with('manager')
            ->where('node_id', $nodeId)
            ->orderBy('id')
            ->get();
    }
}
