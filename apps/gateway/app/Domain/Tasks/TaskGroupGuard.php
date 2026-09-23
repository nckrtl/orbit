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
