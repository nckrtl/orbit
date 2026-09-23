<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property-read AgentThread|null $reviewerThread
 * @property Carbon|null $agent_unavailable_since
 * @property Carbon|null $agent_unavailable_notified_at
 * @property TaskExecutionMode $execution_mode
 * @property string $implementer_agent_driver
 * @property string $reviewer_agent_driver
 * @property int $id
 * @property int $app_id
 * @property string|null $taskable_type
 * @property int|null $taskable_id
 * @property string $title
 * @property string $brief
 * @property TaskGroupStatus $status
 * @property int|null $reviewer_agent_thread_id
 * @property string|null $pr_url
 * @property bool $assistance_requested
 * @property string|null $assistance_reason
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
        'execution_mode' => 'managed',
        'implementer_agent_driver' => 't3',
        'reviewer_agent_driver' => 't3',
        'notify_coder' => false,
        'implementer_model' => TaskAgentDefaults::ImplementerModel,
        'reviewer_model' => TaskAgentDefaults::ReviewerModel,
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'execution_mode',
        'implementer_agent_driver',
        'reviewer_agent_driver',
        'agent_unavailable_since',
        'agent_unavailable_notified_at',
        'app_id',
        'taskable_type',
        'taskable_id',
        'title',
        'brief',
        'status',
        'reviewer_agent_thread_id',
        'pr_url',
        'assistance_requested', 'assistance_reason',
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

    public function requireManagedExecution(): void
    {
        if ($this->execution_mode !== TaskExecutionMode::Managed) {
            throw new ResourceOperationException('tasks.external_execution', 'This task uses an existing thread. Use its annotation controls instead of the managed lifecycle.', 409);
        }
    }

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

    /** @return BelongsTo<AgentThread, $this> */
    public function reviewerThread(): BelongsTo
    {
        return $this->belongsTo(AgentThread::class, 'reviewer_agent_thread_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'notify_coder' => 'boolean',
            'assistance_requested' => 'boolean',
            'agent_unavailable_since' => 'datetime',
            'agent_unavailable_notified_at' => 'datetime',
            'execution_mode' => TaskExecutionMode::class,
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
