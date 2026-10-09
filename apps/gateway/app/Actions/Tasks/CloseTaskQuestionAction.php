<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskQuestions;
use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The operator closes a stale question as answered or superseded. One `question_closed` comment on the subtask
 * records the reason. Closing never delivers a resolution, never changes assistance, and never counts as a consult.
 */
final readonly class CloseTaskQuestionAction
{
    public const int ReasonMax = 2000;

    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    public function execute(TaskQuestion $question, QuestionStatus $status, string $reason, ?string $requestId = null): TaskQuestion
    {
        $this->requireExtension->execute();

        $reason = trim($reason);
        if (! in_array($status, [QuestionStatus::Answered, QuestionStatus::Superseded], true)) {
            throw new ResourceOperationException(errorCode: 'tasks.question_status_invalid', message: __('A question closes as answered or superseded.'), status: 422);
        }
        if ($reason === '' || mb_strlen($reason) > self::ReasonMax) {
            throw new ResourceOperationException(errorCode: 'tasks.question_reason_invalid', message: __('The reason must hold 1 to :max characters.', ['max' => self::ReasonMax]), status: 422);
        }

        return DB::transaction(function () use ($question, $status, $reason, $requestId): TaskQuestion {
            $locked = TaskQuestion::query()->lockForUpdate()->findOrFail($question->id);
            if (! in_array($locked->status, QuestionStatus::unresolved(), true)) {
                if ($locked->status === $status && $locked->answer === $reason) {
                    return $locked;
                }

                throw new ResourceOperationException(errorCode: 'tasks.question_closed', message: __('Question :id is already :status.', ['id' => $locked->id, 'status' => $locked->status->value]), status: 409);
            }

            $subtask = Task::query()->findOrFail($locked->subtask_id);
            $audit = TaskComment::query()->create([
                'task_group_id' => $locked->task_id,
                'task_id' => $subtask->id,
                'completion_attempt' => $subtask->completion_attempt,
                'type' => TaskCommentType::QuestionClosed,
                'body' => "Closed question #{$locked->id} as {$status->value}: {$reason}",
                'author' => QuestionAsker::Operator->value,
                'posted_at' => Carbon::now(),
            ]);
            TaskQuestions::close($locked, $status, $reason, QuestionAsker::Operator, $audit);
            Activity::query()->create([
                'log_name' => 'tasks', 'description' => 'question closed', 'subject_type' => Task::class,
                'subject_id' => $subtask->id,
                'properties' => ['question_id' => $locked->id, 'status' => $status->value, 'reason' => $reason, 'comment_id' => $audit->id],
                'request_id' => $requestId ?? (string) Str::uuid(), 'command' => 'tasks:question:close', 'status' => 'completed',
            ]);

            return $locked->refresh();
        });
    }
}
