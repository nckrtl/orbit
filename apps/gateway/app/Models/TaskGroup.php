<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskGroupStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $app_id
 * @property string|null $taskable_type
 * @property int|null $taskable_id
 * @property string $title
 * @property string $brief
 * @property TaskGroupStatus $status
 * @property string|null $reviewer_thread_id
 * @property string|null $pr_url
 * @property bool $notify_coder
 * @property string $implementer_model
 * @property string $reviewer_model
 * @property int|null $tokens
 * @property int|null $lines_added
 * @property int|null $lines_deleted
 * @property int|null $line_diff
 * @property int|null $duration_ms
 * @property Carbon|null $started_at
 * @property Carbon|null $settled_at
 * @property-read App $app
 * @property-read AppInstance|Model|null $taskable
 * @property-read Collection<int, Task> $tasks
 */
final class TaskGroup extends Model
{
    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'status' => 'queued',
        'notify_coder' => false,
        'implementer_model' => TaskAgentDefaults::ImplementerModel,
        'reviewer_model' => TaskAgentDefaults::ReviewerModel,
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'app_id',
        'taskable_type',
        'taskable_id',
        'title',
        'brief',
        'status',
        'reviewer_thread_id',
        'pr_url',
        'notify_coder',
        'implementer_model',
        'reviewer_model',
        'tokens',
        'line_diff',
        'lines_added',
        'lines_deleted',
        'duration_ms',
        'started_at',
        'settled_at',
    ];

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /** @return MorphTo<Model, $this> */
    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'notify_coder' => 'boolean',
            'status' => TaskGroupStatus::class,
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
