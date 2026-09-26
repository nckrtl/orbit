<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property-read AgentThread|null $implementerThread
 * @property TaskType $type
 * @property string|null $target_thread_id
 * @property string|null $completion_summary
 * @property int $id
 * @property int $task_group_id
 * @property int $position
 * @property int $completion_attempt
 * @property int|null $completion_handoff_comment_id
 * @property int|null $completion_reminder_attempt
 * @property string|null $completion_reminder_input_id
 * @property int|null $completion_handoff_attempt
 * @property string|null $completion_handoff_turn_id
 * @property int $review_attempt
 * @property int|null $review_handled_comment_id
 * @property int|null $review_reminder_attempt
 * @property string|null $review_reminder_input_id
 * @property int|null $review_notified_attempt
 * @property string|null $review_notified_turn_id
 * @property string|null $review_workspace_head
 * @property string|null $review_workspace_tree
 * @property bool $assistance_requested
 * @property string|null $assistance_reason
 * @property int $communication_failures
 * @property int|null $resolution_delivered_comment_id
 * @property string $title
 * @property string $brief
 * @property TaskStatus $status
 * @property int|null $implementer_agent_thread_id
 * @property int|null $tokens
 * @property int|null $lines_added
 * @property int|null $lines_deleted
 * @property int|null $line_diff
 * @property int|null $duration_ms
 * @property Carbon|null $started_at
 * @property string|null $subtask_start_commit
 * @property list<array<string, string|bool>>|null $deliverables
 * @property Carbon|null $settled_at
 * @property-read TaskGroup $taskGroup
 */
final class Task extends Model
{
    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'status' => 'todo',
        'assistance_requested' => false,
        'type' => 'implementation',
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'type', 'target_thread_id', 'completion_summary',
        'task_group_id',
        'position',
        'title',
        'brief',
        'status',
        'implementer_agent_thread_id',
        'tokens',
        'line_diff',
        'lines_added',
        'lines_deleted',
        'duration_ms',
        'started_at',
        'subtask_start_commit',
        'deliverables',
        'settled_at',
        'completion_attempt',
        'completion_handoff_comment_id',
        'completion_reminder_attempt',
        'completion_reminder_input_id',
        'completion_handoff_attempt',
        'completion_handoff_turn_id',
        'review_attempt',
        'review_handled_comment_id',
        'review_reminder_attempt',
        'review_reminder_input_id',
        'review_notified_attempt',
        'review_notified_turn_id',
        'review_workspace_head',
        'review_workspace_tree',
        'assistance_requested', 'assistance_reason', 'communication_failures', 'resolution_delivered_comment_id',
    ];

    /** @return BelongsTo<TaskGroup, $this> */
    public function taskGroup(): BelongsTo
    {
        return $this->belongsTo(TaskGroup::class);
    }

    /** @return BelongsTo<AgentThread, $this> */
    public function implementerThread(): BelongsTo
    {
        return $this->belongsTo(AgentThread::class, 'implementer_agent_thread_id');
    }

    /** @return HasMany<TaskCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(TaskCheck::class);
    }

    /** @return HasMany<TaskComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    /**
     * Whether no other subtask of the group is still to be done.
     */
    public function isLastSubtask(): bool
    {
        return self::query()
            ->where('task_group_id', $this->task_group_id)
            ->whereKeyNot($this->id)
            ->whereIn('status', [TaskStatus::Todo, TaskStatus::Reserved, TaskStatus::Running])
            ->doesntExist();
    }

    /**
     * ADR 0133: the typed items this subtask must deliver. Empty for subtasks created before deliverables existed.
     *
     * @return list<TaskDeliverable>
     */
    public function deliverableList(): array
    {
        return TaskDeliverable::listFrom($this->deliverables);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'position' => 'integer',
            'status' => TaskStatus::class,
            'tokens' => 'integer',
            'line_diff' => 'integer',
            'lines_added' => 'integer',
            'lines_deleted' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'deliverables' => 'array',
            'completion_attempt' => 'integer',
            'completion_handoff_attempt' => 'integer',
            'completion_handoff_comment_id' => 'integer',
            'completion_reminder_attempt' => 'integer',
            'review_attempt' => 'integer',
            'review_handled_comment_id' => 'integer',
            'review_reminder_attempt' => 'integer',
            'review_notified_attempt' => 'integer',
            'assistance_requested' => 'boolean',
            'communication_failures' => 'integer',
            'resolution_delivered_comment_id' => 'integer',
        ];
    }
}
