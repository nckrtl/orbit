<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskCommentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $posted_at
 * @property array<string, string>|null $deliverables the confirmations of a run receipt, by deliverable ID
 */
final class TaskComment extends Model
{
    #[\Override]
    protected $fillable = [
        'task_group_id', 'task_id', 'agent_thread_id', 'completion_attempt', 'type', 'body', 'author',
        'review_attempt', 'commit_sha', 'posted_at', 'receipt_hash', 'pull_request', 'deliverables',
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
        return ['completion_attempt' => 'integer', 'review_attempt' => 'integer', 'type' => TaskCommentType::class, 'posted_at' => 'immutable_datetime', 'pull_request' => 'array', 'deliverables' => 'array'];
    }
}
