<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\StoredInteger;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPositions;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

final readonly class DestroyTaskAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    public function execute(Task $group, Task $task): Task
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();

        return DB::transaction(static function () use ($group, $task): Task {
            $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);

            if ($locked->status !== TaskGroupStatus::Backlog) {
                throw TaskGroupGuard::notInBacklog();
            }

            $task->delete();

            $ids = Task::query()
                ->where('parent_id', $locked->id)
                ->orderBy('position')
                ->pluck('id')
                ->map(static fn (mixed $id): int => StoredInteger::from($id))
                ->values()
                ->all();
            TaskPositions::assign($locked, array_values($ids));

            return $task;
        });
    }
}
