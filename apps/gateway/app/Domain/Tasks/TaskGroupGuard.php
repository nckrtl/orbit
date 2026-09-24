<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;

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

    public static function deliverablesLocked(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.deliverables_locked',
            message: __('Deliverables change only while the group is in backlog or the subtask is todo.'),
            status: 409,
        );
    }

    public static function planRequiresBacklog(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.plan_requires_backlog',
            message: __('A planner starts only for a group created in backlog.'),
            status: 422,
        );
    }

    public static function plannerDriverUnavailable(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.planner_driver_unavailable',
            message: __('A planner needs the T3 driver for the reviewer role.'),
            status: 409,
        );
    }

    public static function plannerNodeUnavailable(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.planner_node_unavailable',
            message: __('No app-dev Node with access to itself can hold this planner.'),
            status: 409,
        );
    }

    public static function plannerUnavailable(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.planner_unavailable',
            message: __('The planner thread could not be started.'),
            status: 409,
        );
    }

    public static function planCommitFailed(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.commit_failed',
            message: __('Orbit could not commit the plan in the group workspace.'),
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

    public static function alreadyClaimed(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.already_claimed',
            message: __('The scheduler has already claimed this task group.'),
            status: 409,
        );
    }
}
