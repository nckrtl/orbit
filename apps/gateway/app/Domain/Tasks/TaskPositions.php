<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/** Keeps a group's subtask positions a gapless sequence from 1 under the unique (parent_id, position) index. */
final class TaskPositions
{
    /** Positions stay clear of this offset while rows move, so no two rows share a position mid-update. */
    private const int Offset = 1_000_000;

    /** @param  list<int>  $orderedIds */
    public static function assign(Task $group, array $orderedIds): void
    {
        Task::query()->where('parent_id', $group->id)->increment('position', self::Offset);

        foreach ($orderedIds as $index => $id) {
            Task::query()->whereKey($id)->update(['position' => $index + 1]);
        }

        app(TaskBroadcasts::class)->groupChanged($group->id);
    }
}
