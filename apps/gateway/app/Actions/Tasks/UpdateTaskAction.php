<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\UpdateTaskData;
use App\Domain\Shared\StoredInteger;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPositions;
use App\Domain\Tasks\TaskStatus;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UpdateTaskAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    public function execute(Task $group, Task $task, UpdateTaskData $data): Task
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();

        return DB::transaction(static function () use ($group, $task, $data): Task {
            $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $backlog = $locked->status === TaskGroupStatus::Backlog;
            $todoOutsideBacklog = $task->status === TaskStatus::Todo && in_array($locked->status, [
                TaskGroupStatus::Todo,
                TaskGroupStatus::Running,
                TaskGroupStatus::Reviewing,
                TaskGroupStatus::Settling,
                TaskGroupStatus::WaitingForReview,
            ], true);

            if (! $backlog && ! $todoOutsideBacklog) {
                if ($data->deliverables !== null && $task->status !== TaskStatus::Todo) {
                    throw TaskGroupGuard::deliverablesLocked();
                }

                throw TaskGroupGuard::notInBacklog();
            }

            // ADR 0133: a started subtask keeps its deliverables. A todo subtask can replace its list after the group starts, but never with none.
            if ($data->deliverables !== null && $task->status !== TaskStatus::Todo) {
                throw TaskGroupGuard::deliverablesLocked();
            }
            if (! $backlog && $data->deliverables === []) {
                throw TaskGroupGuard::deliverablesRequired();
            }

            $task->title = $data->title ?? $task->title;
            $task->brief = $data->brief ?? $task->brief;
            $task->deliverables = $data->deliverables ?? $task->deliverables;
            $task->save();

            if ($data->position !== null && $data->position !== $task->position) {

                $ids = Task::query()
                    ->where('parent_id', $locked->id)
                    ->whereKeyNot($task->id)
                    ->orderBy('position')
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => StoredInteger::from($id))
                    ->values()
                    ->all();
                $ids = array_values($ids);

                if ($todoOutsideBacklog) {
                    $ordered = Task::query()
                        ->where('parent_id', $locked->id)
                        ->orderBy('position')
                        ->get(['id', 'position', 'status']);
                    $lastStartedOrFinished = $ordered
                        ->filter(static fn (Task $candidate): bool => $candidate->status !== TaskStatus::Todo)
                        ->max('position');
                    $tailStart = (is_int($lastStartedOrFinished) ? $lastStartedOrFinished : 0) + 1;

                    if ($task->position < $tailStart || $data->position < $tailStart || $data->position > $ordered->count()) {
                        throw ValidationException::withMessages(['position' => [__('A todo subtask can move only within the todo tail.')]]);
                    }
                } elseif ($data->position > count($ids) + 1) {
                    throw ValidationException::withMessages(['position' => [__('The position must be between 1 and :count.', ['count' => count($ids) + 1])]]);
                }

                array_splice($ids, $data->position - 1, 0, [$task->id]);
                TaskPositions::assign($locked, $ids);
            }

            return $task->refresh();
        });
    }
}
