<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\UpdateTaskData;
use App\Domain\Shared\StoredInteger;
use App\Domain\Tasks\DeliverablePathChecker;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPositions;
use App\Domain\Tasks\TaskReviewBase;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTopology;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class UpdateTaskAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    public function execute(Task $group, Task $task, UpdateTaskData $data, ?Node $actor = null, ?string $requestId = null): Task
    {
        $group->requireManagedExecution();
        $this->requireExtension->execute();

        $group = Task::topLevel()->with('project')->findOrFail($group->id);
        $task = Task::query()->findOrFail($task->id);
        if ($task->parent_id !== $group->id) {
            throw TaskGroupGuard::deliverablesLocked();
        }
        $task->setRelation('parent', $group);
        $check = $data->deliverables === null ? null : TaskGroupGuard::deliverableCorrectionCheck($group, $task);
        $base = TaskReviewBase::commit($task);
        $groupState = $group->getRawOriginal();
        $taskState = $task->getRawOriginal();
        $projectState = $group->project->getRawOriginal();
        if ($check !== null) {
            if ($data->title !== null || $data->brief !== null || $data->position !== null) {
                throw TaskGroupGuard::notInBacklog();
            }
            if ($data->deliverables === []) {
                throw TaskGroupGuard::deliverablesRequired();
            }
            $commit = $base === '' ? app(DeliverablePathRepository::class)->defaultBranchCommit($group->project) : $base;
            $errors = app(DeliverablePathChecker::class)->check($group->project, $data->deliverables ?? [], $commit, $base === '' ? 'provisional' : 'resolved');
            if ($errors !== []) {
                $messages = [];
                foreach ($errors as $field => $message) {
                    $messages['deliverables.'.$field] = [$message];
                }
                throw ValidationException::withMessages($messages);
            }
        }

        return DB::transaction(static function () use ($group, $task, $data, $actor, $requestId, $check, $base, $groupState, $taskState, $projectState): Task {
            $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($task->parent_id !== $locked->id) {
                throw TaskGroupGuard::deliverablesLocked();
            }
            $task->setRelation('parent', $locked);
            if ($check !== null && TaskGroupGuard::deliverableCorrectionCheck($locked, $task)?->id !== $check->id) {
                throw TaskGroupGuard::deliverablesLocked();
            }
            if ($data->deliverables !== null && ($currentCheck = TaskGroupGuard::deliverableCorrectionCheck($locked, $task)) !== null) {
                if ($data->title !== null || $data->brief !== null || $data->position !== null) {
                    throw TaskGroupGuard::notInBacklog();
                }
                if ($data->deliverables === []) {
                    throw TaskGroupGuard::deliverablesRequired();
                }

                // Repository I/O has finished. Reject a stale validation instead of consuming recovery.
                if ($check?->id !== $currentCheck->id || $locked->getRawOriginal() !== $groupState
                    || $task->getRawOriginal() !== $taskState || TaskReviewBase::commit($task) !== $base
                    || $locked->project->getRawOriginal() !== $projectState) {
                    throw TaskGroupGuard::deliverablesLocked();
                }

                $old = $task->deliverables;
                $task->update(['deliverables' => $data->deliverables, 'deliverable_correction_check_id' => $currentCheck->id]);
                Activity::query()->create([
                    'log_name' => 'tasks', 'description' => 'deliverables corrected', 'subject_type' => Task::class,
                    'subject_id' => $task->id, 'properties' => ['check_id' => $currentCheck->id, 'old' => $old, 'new' => $data->deliverables],
                    'caller_node_id' => $actor?->id, 'caller_ip' => $actor?->wireguard_ip,
                    'request_id' => $requestId ?? (string) Str::uuid(), 'command' => 'tasks:subtask:update', 'status' => 'completed',
                ]);

                return $task->refresh();
            }

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

            if ($data->topology !== null) {
                if ($task->status !== TaskStatus::Todo || $task->subtask_start_commit !== null) {
                    throw ValidationException::withMessages(['topology' => ['A started subtask cannot change its topology.']]);
                }
                $task->topology = TaskTopology::from($data->topology);
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
