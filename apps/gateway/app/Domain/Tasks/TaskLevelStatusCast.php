<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Top-level rows use the task status names. Subtask rows use the subtask names.
 * The level is the task's loaded parent id, the same decision the broadcast observer makes.
 *
 * @implements CastsAttributes<TaskStatus|TaskGroupStatus|null, TaskStatus|TaskGroupStatus|string|null>
 */
final class TaskLevelStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): TaskStatus|TaskGroupStatus|null
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! $model instanceof Task) {
            throw new LogicException('Task status belongs on a task.');
        }

        return $model->isTopLevel()
            ? TaskGroupStatus::from($value)
            : TaskStatus::from($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof TaskStatus || $value instanceof TaskGroupStatus) {
            return $value->value;
        }

        return $value;
    }
}
