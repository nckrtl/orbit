<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionCause;
use App\Domain\Tasks\QuestionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One consult or direction request. Comments keep the conversation.
 *
 * @property int $id
 * @property int $task_id
 * @property int $subtask_id
 * @property int $attempt
 * @property QuestionAsker $asked_by
 * @property string $question
 * @property QuestionStatus $status
 * @property QuestionAsker|null $answered_by
 * @property string|null $answer
 * @property QuestionCause|null $cause
 * @property Carbon $asked_at
 * @property Carbon|null $escalated_at
 * @property Carbon|null $answered_at
 * @property bool $consult
 * @property int|null $opened_comment_id
 * @property int|null $resolution_comment_id
 * @property int|null $answered_comment_id
 */
final class TaskQuestion extends Model
{
    #[\Override]
    protected $fillable = [
        'task_id', 'subtask_id', 'attempt', 'asked_by', 'question', 'status', 'answered_by', 'answer', 'cause',
        'consult', 'asked_at', 'escalated_at', 'answered_at', 'opened_comment_id', 'resolution_comment_id', 'answered_comment_id',
    ];

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id')->withoutGlobalScope('subtask');
    }

    /** @return BelongsTo<Task, $this> */
    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'subtask_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'asked_by' => QuestionAsker::class,
            'status' => QuestionStatus::class,
            'answered_by' => QuestionAsker::class,
            'cause' => QuestionCause::class,
            'consult' => 'boolean',
            'asked_at' => 'datetime',
            'escalated_at' => 'datetime',
            'answered_at' => 'datetime',
            'opened_comment_id' => 'integer',
            'resolution_comment_id' => 'integer',
            'answered_comment_id' => 'integer',
        ];
    }
}
