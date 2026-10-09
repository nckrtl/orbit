<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelRunningSubtaskAction;
use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CloseTaskQuestionAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskQuestion;

/** A group without a workspace, with one subtask per status, in position order. */
function question_close_group(TaskGroupStatus $status, TaskStatus ...$subtasks): Task
{
    $project = Project::query()->create([
        'name' => 'question-app',
        'slug' => 'question-app',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Close stale questions',
        'brief' => 'Questions end with their subtask.',
        'status' => $status,
    ]);
    foreach ($subtasks as $index => $subtaskStatus) {
        Task::query()->create([
            'parent_id' => $group->id,
            'position' => $index + 1,
            'title' => 'Subtask '.($index + 1),
            'brief' => 'Part of the group.',
            'status' => $subtaskStatus,
            'questions' => 2,
            'escalations' => 1,
        ]);
    }

    return $group->fresh(['tasks']) ?? $group;
}

function question_close_subtask(Task $group, int $position = 1): Task
{
    return Task::query()->where('parent_id', $group->id)->where('position', $position)->sole();
}

function question_close_question(Task $subtask, QuestionStatus $status, ?int $resolutionCommentId = null): TaskQuestion
{
    return TaskQuestion::query()->create([
        'task_id' => $subtask->parent_id,
        'subtask_id' => $subtask->id,
        'attempt' => 1,
        'asked_by' => QuestionAsker::Implementer,
        'question' => 'Which mirror?',
        'status' => $status,
        'answer' => $status === QuestionStatus::Answered ? 'The public mirror.' : null,
        'answered_by' => $status === QuestionStatus::Answered ? QuestionAsker::Reviewer : null,
        'consult' => false,
        'asked_at' => now(),
        'escalated_at' => $status === QuestionStatus::Open ? null : now(),
        'resolution_comment_id' => $resolutionCommentId,
    ]);
}

function question_close_resolution(Task $subtask): TaskComment
{
    return TaskComment::query()->create([
        'task_group_id' => $subtask->parent_id,
        'task_id' => $subtask->id,
        'type' => TaskCommentType::Resolution,
        'body' => 'Use the public mirror.',
        'author' => 'operator',
        'posted_at' => now(),
    ]);
}

beforeEach(function (): void {
    app(TaskExtensionState::class)->enable();
});

describe('closing a question', function (): void {
    it('closes a stuck escalated question without touching assistance', function (QuestionStatus $status): void {
        $group = question_close_group(TaskGroupStatus::Running, TaskStatus::Running);
        $subtask = question_close_subtask($group);
        $resolution = question_close_resolution($subtask);
        $question = question_close_question($subtask, QuestionStatus::Escalated, $resolution->id);
        $group->update(['assistance_requested' => false, 'assistance_kind' => AssistanceKind::Direction, 'assistance_reason' => 'Which mirror?']);
        $subtask->update(['assistance_requested' => false, 'assistance_kind' => AssistanceKind::Direction, 'assistance_reason' => 'Which mirror?']);

        $closed = app(CloseTaskQuestionAction::class)->execute($question, $status, '  Answered in resolution 2803.  ');

        $audit = TaskComment::query()->where('type', TaskCommentType::QuestionClosed)->sole();
        expect($closed->status)->toBe($status)
            ->and($closed->answer)->toBe('Answered in resolution 2803.')
            ->and($closed->answered_by)->toBe(QuestionAsker::Operator)
            ->and($closed->answered_at)->not->toBeNull()
            ->and($closed->answered_comment_id)->toBe($audit->id)
            ->and($closed->resolution_comment_id)->toBe($resolution->id)
            ->and($audit->task_id)->toBe($subtask->id)
            ->and($audit->body)->toBe("Closed question #{$question->id} as {$status->value}: Answered in resolution 2803.")
            ->and(Activity::query()->where('description', 'question closed')->sole()->properties['question_id'] ?? null)->toBe($question->id);
        foreach ([$group->fresh(), $subtask->fresh()] as $task) {
            expect($task?->assistance_requested)->toBeFalse()
                ->and($task?->assistance_kind)->toBe(AssistanceKind::Direction)
                ->and($task?->assistance_reason)->toBe('Which mirror?');
        }
        expect($subtask->fresh()?->questions)->toBe(2)
            ->and($subtask->fresh()?->escalations)->toBe(1)
            ->and($subtask->fresh()?->completion_attempt)->toBe($subtask->completion_attempt);
    })->with([
        'superseded' => [QuestionStatus::Superseded],
        'answered' => [QuestionStatus::Answered],
    ]);

    it('closes an open question left on a cancelled subtask as superseded', function (): void {
        $group = question_close_group(TaskGroupStatus::Running, TaskStatus::Cancelled, TaskStatus::Running);
        $cancelled = question_close_subtask($group);
        $question = question_close_question($cancelled, QuestionStatus::Open, question_close_resolution($cancelled)->id);

        $closed = app(CloseTaskQuestionAction::class)->execute($question, QuestionStatus::Superseded, 'Subtask 1309 owns the continuation.');

        expect($closed->status)->toBe(QuestionStatus::Superseded)
            ->and($closed->answer)->toBe('Subtask 1309 owns the continuation.')
            ->and($cancelled->fresh()?->status)->toBe(TaskStatus::Cancelled)
            ->and($cancelled->fresh()?->assistance_requested)->toBeFalse();
    });

    it('refuses a closed question, and repeats the same close without a second write', function (): void {
        $group = question_close_group(TaskGroupStatus::Running, TaskStatus::Running);
        $question = question_close_question(question_close_subtask($group), QuestionStatus::Escalated);
        $action = app(CloseTaskQuestionAction::class);
        $first = $action->execute($question, QuestionStatus::Superseded, 'Stale.');

        $again = $action->execute($question, QuestionStatus::Superseded, 'Stale.');

        expect($again->answered_at?->toIso8601String())->toBe($first->answered_at?->toIso8601String())
            ->and(TaskComment::query()->where('type', TaskCommentType::QuestionClosed)->count())->toBe(1);
        expect(fn () => $action->execute($question, QuestionStatus::Answered, 'Stale.'))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('tasks.question_closed')->and($exception->status)->toBe(409);
            });
        expect(fn () => $action->execute($question, QuestionStatus::Superseded, 'Another reason.'))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->status)->toBe(409));
    });

    it('refuses a question answered by the reviewer', function (): void {
        $group = question_close_group(TaskGroupStatus::Running, TaskStatus::Running);
        $question = question_close_question(question_close_subtask($group), QuestionStatus::Answered);

        expect(fn () => app(CloseTaskQuestionAction::class)->execute($question, QuestionStatus::Superseded, 'Stale.'))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->status)->toBe(409));
        expect($question->fresh()?->answer)->toBe('The public mirror.');
    });

    it('refuses an open target status and an empty reason', function (QuestionStatus $status, string $reason, string $code): void {
        $group = question_close_group(TaskGroupStatus::Running, TaskStatus::Running);
        $question = question_close_question(question_close_subtask($group), QuestionStatus::Escalated);

        expect(fn () => app(CloseTaskQuestionAction::class)->execute($question, $status, $reason))
            ->toThrow(function (ResourceOperationException $exception) use ($code): void {
                expect($exception->errorCode)->toBe($code)->and($exception->status)->toBe(422);
            });
        expect($question->fresh()?->status)->toBe(QuestionStatus::Escalated)
            ->and(TaskComment::query()->where('type', TaskCommentType::QuestionClosed)->exists())->toBeFalse();
    })->with([
        'open' => [QuestionStatus::Open, 'Stale.', 'tasks.question_status_invalid'],
        'escalated' => [QuestionStatus::Escalated, 'Stale.', 'tasks.question_status_invalid'],
        'empty reason' => [QuestionStatus::Superseded, '   ', 'tasks.question_reason_invalid'],
    ]);
});

describe('ending a subtask or a task', function (): void {
    it('supersedes the questions of a cancelled subtask', function (): void {
        $group = question_close_group(TaskGroupStatus::Running, TaskStatus::Todo, TaskStatus::Todo);
        $subtask = question_close_subtask($group);
        $open = question_close_question($subtask, QuestionStatus::Open);
        $escalated = question_close_question($subtask, QuestionStatus::Escalated);
        $answered = question_close_question($subtask, QuestionStatus::Answered);
        $sibling = question_close_question(question_close_subtask($group, 2), QuestionStatus::Escalated);

        app(CancelRunningSubtaskAction::class)->execute($group, $subtask);

        foreach ([$open, $escalated] as $question) {
            expect($question->fresh()?->status)->toBe(QuestionStatus::Superseded)
                ->and($question->fresh()?->answer)->toBe('Subtask cancelled.')
                ->and($question->fresh()?->answered_at)->not->toBeNull()
                ->and($question->fresh()?->answered_by)->toBeNull();
        }
        expect($escalated->fresh()?->escalated_at)->not->toBeNull()
            ->and($answered->fresh()?->status)->toBe(QuestionStatus::Answered)
            ->and($answered->fresh()?->answer)->toBe('The public mirror.')
            ->and($sibling->fresh()?->status)->toBe(QuestionStatus::Escalated)
            ->and($subtask->fresh()?->questions)->toBe(2)
            ->and($subtask->fresh()?->escalations)->toBe(1);
    });

    it('supersedes the questions of a completed subtask', function (): void {
        $group = question_close_group(TaskGroupStatus::Reviewing, TaskStatus::Reviewing);
        $subtask = question_close_subtask($group);
        $question = question_close_question($subtask, QuestionStatus::Escalated);

        $subtask->update(['status' => TaskStatus::Completed]);

        expect($question->fresh()?->status)->toBe(QuestionStatus::Superseded)
            ->and($question->fresh()?->answer)->toBe('Subtask completed.');
    });

    it('supersedes every open question when a task is cancelled', function (): void {
        $group = question_close_group(TaskGroupStatus::Running, TaskStatus::Cancelled, TaskStatus::Running, TaskStatus::Todo);
        $questions = [
            question_close_question(question_close_subtask($group, 1), QuestionStatus::Open),
            question_close_question(question_close_subtask($group, 2), QuestionStatus::Escalated),
            question_close_question(question_close_subtask($group, 3), QuestionStatus::Open),
        ];
        $answered = question_close_question(question_close_subtask($group, 2), QuestionStatus::Answered);

        app(CancelTaskGroupAction::class)->execute($group);

        foreach ($questions as $question) {
            expect($question->fresh()?->status)->toBe(QuestionStatus::Superseded)
                ->and($question->fresh()?->answer)->toBe('Task cancelled.');
        }
        expect($answered->fresh()?->status)->toBe(QuestionStatus::Answered)
            ->and(question_close_subtask($group, 2)->questions)->toBe(2)
            ->and(question_close_subtask($group, 2)->escalations)->toBe(1);
    });

    it('supersedes every open question when a settling task completes', function (): void {
        $group = question_close_group(TaskGroupStatus::Settling, TaskStatus::Completed, TaskStatus::Cancelled);
        $completed = question_close_question(question_close_subtask($group, 1), QuestionStatus::Escalated);
        $cancelled = question_close_question(question_close_subtask($group, 2), QuestionStatus::Open);

        app(CompleteTaskGroupAction::class)->execute($group);

        expect($group->fresh()?->status)->toBe(TaskGroupStatus::Completed);
        foreach ([$completed, $cancelled] as $question) {
            expect($question->fresh()?->status)->toBe(QuestionStatus::Superseded)
                ->and($question->fresh()?->answer)->toBe('Task completed.');
        }
    });
});
