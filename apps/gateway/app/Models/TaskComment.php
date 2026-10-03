<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\QuestionCause;
use App\Domain\Tasks\TaskBroadcastObserver;
use App\Domain\Tasks\TaskCommentType;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $posted_at
 * @property array<string, string>|null $deliverables the confirmations of a turn receipt, by deliverable ID
 * @property QuestionCause|null $cause
 * @property array{source_turn_id: string|null}|null $topology_resume
 */
#[ObservedBy([TaskBroadcastObserver::class])]
final class TaskComment extends Model
{
    #[\Override]
    protected $fillable = [
        'task_group_id', 'task_id', 'agent_thread_id', 'completion_attempt', 'type', 'body', 'author',
        'review_attempt', 'commit_sha', 'posted_at', 'receipt_hash', 'pull_request', 'deliverables', 'cause', 'topology_resume',
    ];

    /**
     * The top-level task that owns this comment. The column still stores that id.
     *
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_group_id')->withoutGlobalScope('subtask');
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
        return ['completion_attempt' => 'integer', 'review_attempt' => 'integer', 'type' => TaskCommentType::class, 'posted_at' => 'immutable_datetime', 'pull_request' => 'array', 'deliverables' => 'array', 'cause' => QuestionCause::class, 'topology_resume' => 'array'];
    }
}
