<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskReviewedCommitSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ADR 0203: one commit Orbit fully reviewed for a task. Only these heads can merge.
 *
 * @property int $id
 * @property int $task_id
 * @property string $sha
 * @property TaskReviewedCommitSource $source
 * @property int|null $review_task_id
 * @property Carbon|null $pushed_at
 * @property int|null $github_review_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class TaskReviewedCommit extends Model
{
    #[\Override]
    protected $fillable = ['task_id', 'sha', 'source', 'review_task_id', 'pushed_at', 'github_review_id'];

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id')->withoutGlobalScope('subtask');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => TaskReviewedCommitSource::class,
            'review_task_id' => 'integer',
            'pushed_at' => 'datetime',
            'github_review_id' => 'integer',
        ];
    }
}
