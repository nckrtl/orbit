<?php

declare(strict_types=1);

namespace App\Actions\Activities;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListActivitiesAction
{
    /** @return Collection<int, Activity> */
    public function handle(
        int $limit,
        string $excludeRequestId,
        ?string $requestId = null,
        ?int $beforeId = null,
        ?string $status = null,
        ?string $command = null,
        ?int $callerNodeId = null,
        ?int $targetNodeId = null,
    ): Collection {
        return Activity::query()
            ->when(
                $excludeRequestId !== '',
                static fn ($query) => $query->where('request_id', '!=', $excludeRequestId),
            )
            ->when(
                $requestId !== null,
                static fn ($query) => $query->where('request_id', $requestId),
            )
            ->when(
                $beforeId !== null,
                static fn ($query) => $query->where('id', '<', $beforeId),
            )
            ->when(
                $status !== null,
                static fn ($query) => $query->where('status', $status),
            )
            ->when(
                $command !== null,
                static fn ($query) => $query->where('command', $command),
            )
            ->when(
                $callerNodeId !== null,
                static fn ($query) => $query->where('caller_node_id', $callerNodeId),
            )
            ->when(
                $targetNodeId !== null,
                static fn ($query) => $query->where('target_node_id', $targetNodeId),
            )
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
