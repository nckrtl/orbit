<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use Illuminate\Support\Facades\DB;

/**
 * Writes one question record for each consult and each direction request.
 * The same comment never creates a second row. A consult the reviewer escalates is that same row.
 */
final class TaskQuestions
{
    /** An implementer attempt consults the reviewer at most this many times (ADR 0187). */
    public const int ConsultLimit = 2;

    public static function recordBlocked(Task $task, TaskComment $receipt): void
    {
        if (self::opened($receipt)) {
            return;
        }

        $task->refresh();
        if ($task->direction_relay_comment_id !== null) {
            self::reviseRelay($task, $receipt);
            $task->update(['direction_relay_comment_id' => null]);
            self::storeCounts($task);

            return;
        }

        self::answerPending($task, $receipt);
        self::open($task, $receipt, self::asker($receipt), self::questionText($receipt, self::asker($receipt)));
        self::storeCounts($task);
    }

    public static function recordOperator(Task $task, TaskComment $comment): void
    {
        if (self::opened($comment)) {
            return;
        }

        $task->refresh();
        self::open($task, $comment, QuestionAsker::Operator, $comment->body);
        self::storeCounts($task);
    }

    /** Opens the consult for an implementer's blocked receipt. A repeated comment does not count again. */
    public static function openConsult(Task $task, TaskComment $receipt): void
    {
        if (self::opened($receipt)) {
            return;
        }
        $parentId = $task->parent_id;
        if (! is_int($parentId)) {
            return;
        }

        TaskQuestion::query()->create([
            'task_id' => $parentId,
            'subtask_id' => $task->id,
            'attempt' => max(1, (int) $task->completion_attempt),
            'asked_by' => QuestionAsker::Implementer,
            'question' => TaskAssistance::questionFromBlockedReason($receipt->body),
            'status' => QuestionStatus::Open,
            'consult' => true,
            'asked_at' => now(),
            'opened_comment_id' => $receipt->id,
        ]);
        self::storeCounts($task);
    }

    /**
     * Moves the open consult to escalated. Returns false when this receipt did not escalate one,
     * so the caller records a new direction request instead.
     */
    public static function escalateOpen(Task $task, TaskComment $receipt): bool
    {
        $question = self::openConsultRow($task);
        if (! $question instanceof TaskQuestion) {
            return false;
        }

        $question->update([
            'question' => TaskAssistance::questionFromBlockedReason($receipt->body),
            'status' => QuestionStatus::Escalated,
            'cause' => self::cause($receipt),
            'escalated_at' => now(),
        ]);
        self::storeCounts($task);

        return true;
    }

    /** The reviewer answered the open consult. A repeated receipt does not write a second answer. */
    public static function answerConsult(Task $task, TaskComment $receipt): bool
    {
        if (TaskQuestion::query()->where('subtask_id', $task->id)->where('consult', true)->where('answered_comment_id', $receipt->id)->exists()) {
            return true;
        }
        $question = self::openConsultRow($task);
        $cause = self::cause($receipt);
        if (! $question instanceof TaskQuestion || ! $cause instanceof QuestionCause) {
            return false;
        }

        $question->update([
            'status' => QuestionStatus::Answered,
            'answered_by' => QuestionAsker::Reviewer,
            'answer' => $receipt->body,
            'cause' => $cause,
            'answered_at' => now(),
            'answered_comment_id' => $receipt->id,
        ]);
        self::storeCounts($task);

        return true;
    }

    /** Consult rows for the subtask's current implementer attempt. A relay and a third block are not consults. */
    public static function consultCount(Task $task): int
    {
        return TaskQuestion::query()
            ->where('subtask_id', $task->id)
            ->where('attempt', max(1, (int) $task->completion_attempt))
            ->where('consult', true)
            ->count();
    }

    /** @return list<string> */
    public static function earlierAnswers(Task $task): array
    {
        return array_values(TaskQuestion::query()
            ->where('subtask_id', $task->id)
            ->where('attempt', max(1, (int) $task->completion_attempt))
            ->where('consult', true)
            ->orderBy('id')
            ->pluck('answer')
            ->map(static fn (mixed $answer): string => is_string($answer) ? $answer : '')
            ->all());
    }

    /** Links the resolution to the open direction record without answering it. */
    public static function attachResolution(Task $task, TaskComment $comment): void
    {
        $question = TaskQuestion::query()
            ->where('subtask_id', $task->id)
            ->where('status', QuestionStatus::Escalated)
            ->whereNull('resolution_comment_id')
            ->latest('id')
            ->first();
        if ($question instanceof TaskQuestion) {
            $question->update(['resolution_comment_id' => $comment->id]);
        }
    }

    /**
     * Closes one open or escalated question as answered or superseded, with the operator's reason as its answer.
     * It neither delivers a resolution nor changes assistance, and the question counts stay as they were.
     */
    public static function close(TaskQuestion $question, QuestionStatus $status, string $reason, QuestionAsker $by, TaskComment $audit): void
    {
        if (! in_array($question->status, QuestionStatus::unresolved(), true)) {
            return;
        }

        $question->update([
            'status' => $status,
            'answered_by' => $by,
            'answer' => $reason,
            'answered_at' => now(),
            'answered_comment_id' => $audit->id,
        ]);
    }

    /**
     * Supersedes every open or escalated question on a task that ended. A subtask settles its own questions,
     * and a top-level task settles the questions of all its subtasks.
     */
    public static function settle(Task $task, string $reason): void
    {
        TaskQuestion::query()
            ->where($task->isTopLevel() ? 'task_id' : 'subtask_id', $task->id)
            ->whereIn('status', QuestionStatus::unresolved())
            ->update([
                'status' => QuestionStatus::Superseded,
                'answer' => $reason,
                'answered_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public static function awaitsCause(Task $task): bool
    {
        return self::pending($task) instanceof TaskQuestion;
    }

    /** The receipt after a direction resolution answers that record. A missing cause leaves it escalated. */
    public static function answerPending(Task $task, TaskComment $receipt): bool
    {
        $question = self::pending($task);
        if (! $question instanceof TaskQuestion) {
            return false;
        }
        if ($question->answered_comment_id === $receipt->id) {
            return true;
        }
        $cause = self::cause($receipt);
        $resolutionId = $question->resolution_comment_id;
        $resolution = is_int($resolutionId) ? TaskComment::query()->find($resolutionId) : null;
        if (! $cause instanceof QuestionCause || ! $resolution instanceof TaskComment) {
            return false;
        }

        $question->update([
            'status' => QuestionStatus::Answered,
            'answered_by' => QuestionAsker::Operator,
            'answer' => $resolution->body,
            'cause' => $cause,
            'answered_at' => now(),
            'answered_comment_id' => $receipt->id,
        ]);
        self::storeCounts($task);

        return true;
    }

    private static function reviseRelay(Task $task, TaskComment $receipt): void
    {
        $question = TaskQuestion::query()
            ->where('subtask_id', $task->id)
            ->where('status', QuestionStatus::Escalated)
            ->where('resolution_comment_id', $task->direction_relay_comment_id)
            ->latest('id')
            ->first();
        if (! $question instanceof TaskQuestion) {
            $question = TaskQuestion::query()
                ->where('subtask_id', $task->id)
                ->where('status', QuestionStatus::Escalated)
                ->latest('id')
                ->first();
        }
        if (! $question instanceof TaskQuestion) {
            self::open($task, $receipt, QuestionAsker::Reviewer, TaskAssistance::questionFromBlockedReason($receipt->body));

            return;
        }

        $question->update([
            'question' => TaskAssistance::questionFromBlockedReason($receipt->body),
            'cause' => self::cause($receipt),
            'resolution_comment_id' => null,
        ]);
    }

    private static function open(Task $task, TaskComment $comment, QuestionAsker $asker, string $question): void
    {
        $parentId = $task->parent_id;
        if (! is_int($parentId)) {
            return;
        }

        TaskQuestion::query()->create([
            'task_id' => $parentId,
            'subtask_id' => $task->id,
            'attempt' => self::attempt($task, $asker),
            'asked_by' => $asker,
            'question' => $question,
            'status' => QuestionStatus::Escalated,
            'consult' => false,
            'cause' => $asker === QuestionAsker::Reviewer ? self::cause($comment) : null,
            'asked_at' => now(),
            'escalated_at' => now(),
            'opened_comment_id' => $comment->id,
        ]);
    }

    private static function openConsultRow(Task $task): ?TaskQuestion
    {
        $question = TaskQuestion::query()
            ->where('subtask_id', $task->id)
            ->where('consult', true)
            ->where('status', QuestionStatus::Open)
            ->latest('id')
            ->first();

        return $question instanceof TaskQuestion ? $question : null;
    }

    private static function pending(Task $task): ?TaskQuestion
    {
        $question = TaskQuestion::query()
            ->where('subtask_id', $task->id)
            ->where('status', QuestionStatus::Escalated)
            ->whereNotNull('resolution_comment_id')
            ->latest('id')
            ->first();

        return $question instanceof TaskQuestion ? $question : null;
    }

    private static function opened(TaskComment $comment): bool
    {
        return TaskQuestion::query()->where('opened_comment_id', $comment->id)->exists();
    }

    private static function asker(TaskComment $comment): QuestionAsker
    {
        return match ($comment->author) {
            QuestionAsker::Reviewer->value => QuestionAsker::Reviewer,
            QuestionAsker::Operator->value => QuestionAsker::Operator,
            default => QuestionAsker::Implementer,
        };
    }

    private static function questionText(TaskComment $comment, QuestionAsker $asker): string
    {
        return $asker === QuestionAsker::Operator ? $comment->body : TaskAssistance::questionFromBlockedReason($comment->body);
    }

    private static function attempt(Task $task, QuestionAsker $asker): int
    {
        if ($asker === QuestionAsker::Reviewer || ($asker === QuestionAsker::Operator && $task->status === TaskStatus::Reviewing)) {
            return max(1, (int) $task->review_attempt);
        }

        return max(1, (int) $task->completion_attempt);
    }

    private static function cause(TaskComment $comment): ?QuestionCause
    {
        return $comment->cause;
    }

    private static function storeCounts(Task $task): void
    {
        $parentId = $task->parent_id;
        if (! is_int($parentId)) {
            return;
        }

        DB::table('tasks')->where('id', $task->id)->update([
            'questions' => TaskQuestion::query()->where('subtask_id', $task->id)->count(),
            'escalations' => TaskQuestion::query()->where('subtask_id', $task->id)->whereNotNull('escalated_at')->count(),
            'updated_at' => now(),
        ]);
        $row = DB::table('tasks')->where('parent_id', $parentId)
            ->selectRaw('coalesce(sum(questions), 0) as questions, coalesce(sum(escalations), 0) as escalations')
            ->first();
        DB::table('tasks')->where('id', $parentId)->update([
            'questions' => (int) ($row->questions ?? 0),
            'escalations' => (int) ($row->escalations ?? 0),
            'updated_at' => now(),
        ]);
    }
}
