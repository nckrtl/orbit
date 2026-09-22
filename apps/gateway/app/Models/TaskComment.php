<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon $posted_at */
final class TaskComment extends Model
{
    #[\Override]
    protected $fillable = [
        'task_group_id', 'task_id', 'agent_thread_id', 'completion_attempt', 'type', 'body', 'author',
        'review_attempt', 'reviewer_thread_id', 'driver_turn', 'commit_sha', 'pr_url', 'posted_at',
    ];

    /** @return BelongsTo<TaskGroup, $this> */
    public function taskGroup(): BelongsTo
    {
        return $this->belongsTo(TaskGroup::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<AgentThread, $this> */
    public function agentThread(): BelongsTo
    {
        return $this->belongsTo(AgentThread::class);
    }

    protected function casts(): array
    {
        return ['completion_attempt' => 'integer', 'review_attempt' => 'integer', 'posted_at' => 'immutable_datetime'];
    }
}
