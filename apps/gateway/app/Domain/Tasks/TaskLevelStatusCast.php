<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Top-level rows use the task status names. Subtask rows use the subtask names.
 * The stored strings match, and a partial select that omits parent_id stays a subtask read.
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

        if ($model instanceof Task
            && TaskSchema::merged($model->getConnection())
            && array_key_exists('parent_id', $attributes)
            && $attributes['parent_id'] === null) {
            return TaskGroupStatus::from($value);
        }

        return TaskStatus::from($value);
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
