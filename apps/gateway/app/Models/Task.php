<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $task_group_id
 * @property int $position
 * @property string $title
 * @property string $brief
 * @property TaskStatus $status
 * @property string|null $implementer_thread_id
 * @property int|null $tokens
 * @property int|null $lines_added
 * @property int|null $lines_deleted
 * @property int|null $line_diff
 * @property int|null $duration_ms
 * @property Carbon|null $started_at
 * @property Carbon|null $settled_at
 * @property-read TaskGroup $taskGroup
 */
final class Task extends Model
{
    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'status' => 'pending',
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'task_group_id',
        'position',
        'title',
        'brief',
        'status',
        'implementer_thread_id',
        'tokens',
        'line_diff',
        'lines_added',
        'lines_deleted',
        'duration_ms',
        'started_at',
        'settled_at',
    ];

    /** @return BelongsTo<TaskGroup, $this> */
    public function taskGroup(): BelongsTo
    {
        return $this->belongsTo(TaskGroup::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => TaskStatus::class,
            'tokens' => 'integer',
            'line_diff' => 'integer',
            'lines_added' => 'integer',
            'lines_deleted' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }
}
