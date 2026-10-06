<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Task;
use App\Models\TaskCheck;

/** ADR 0122: the refusals that keep Backlog preparation apart from scheduled work. */
final class TaskGroupGuard
{
    public static function noSubtasks(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.no_subtasks',
            message: __('A task group needs at least one subtask before it moves to todo.'),
            status: 422,
        );
    }

    /**
     * ADR 0133: a group moves to todo only when every subtask has deliverables.
     *
     * @param  list<string>  $subtasks  the subtasks without deliverables
     */
    public static function deliverablesMissing(array $subtasks): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.subtask_deliverables_missing',
            message: __('Every subtask needs at least one deliverable before the group moves to todo.'),
            status: 422,
            details: ['subtasks' => implode(', ', $subtasks)],
        );
    }

    /** ADR 0133: a subtask of a group that left backlog is never without deliverables. */
    public static function deliverablesRequired(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.subtask_deliverables_missing',
            message: __('A subtask of a group outside backlog needs at least one deliverable.'),
            status: 422,
        );
    }

    /** Called under the group and subtask locks before consuming the one recovery. */
    public static function deliverableCorrectionCheck(Task $group, Task $task): ?TaskCheck
    {
        if ($group->status !== TaskGroupStatus::Running || $task->status !== TaskStatus::Running
            || ! $task->assistance_requested || self::deliverableCorrectionRecorded($task)) {
            return null;
        }

        $check = TaskCheck::query()->where('task_id', $task->id)->where('kind', TaskCheckKind::Handoff)->latest('id')->first();
        if (! $check instanceof TaskCheck || $check->status !== TaskCheckStatus::Failed
            || $check->failed_step !== 'invalid_deliverable'
            || $check->task_comment_id !== $task->completion_handoff_comment_id) {
            return null;
        }
        $receipt = $task->comments()->find($check->task_comment_id);

        return $receipt?->completion_attempt === $task->completion_attempt ? $check : null;
    }

    public static function deliverableCorrectionRecorded(Task $task): bool
    {
        return $task->deliverable_correction_check_id !== null;
    }

    public static function deliverablesLocked(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.deliverables_locked',
            message: __('Deliverables change only while the group is in backlog or the subtask is todo.'),
            status: 409,
        );
    }

    public static function notInBacklog(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.not_in_backlog',
            message: __('This change is allowed only while the task group is in backlog.'),
            status: 409,
        );
    }

    public static function groupClosed(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.group_closed',
            message: __('A completed or cancelled task group cannot accept new subtasks.'),
            status: 409,
        );
    }

    public static function alreadyClaimed(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.already_claimed',
            message: __('The scheduler has already claimed this task group.'),
            status: 409,
        );
    }
}
