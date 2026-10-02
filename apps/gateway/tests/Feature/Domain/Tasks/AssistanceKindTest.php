<?php

declare(strict_types=1);

use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\Tasks\HttpCoderSettleNotifier;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function assistance_kind_group(): Task
{
    $project = Project::query()->create([
        'name' => 'Assistance',
        'slug' => 'assistance-kind',
        'repository_url' => 'git@example.test:assistance.git',
        'default_branch' => 'main',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Assistance',
        'brief' => 'Classify the request.',
        'status' => TaskGroupStatus::Running,
    ]);
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Only',
        'brief' => 'One subtask.',
        'status' => TaskStatus::Running,
    ]);

    return $group->fresh(['tasks']) ?? $group;
}

it('stores an operator assistance comment as a direction request', function (): void {
    $group = assistance_kind_group();
    $task = $group->tasks->sole();
    $question = 'Which database should this subtask use?';

    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'assistance_requested',
        'body' => $question,
        'author' => 'operator',
    ]);

    $task->refresh();
    $group->refresh();
    expect($task->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->assistance_question)->toBe($question)
        ->and($task->assistance_reason)->toBe($question)
        ->and($group->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->assistance_question)->toBe($question)
        ->and($group->assistance_reason)->toBe($question);
});

it('replaces an open failure with the operator direction request', function (): void {
    $group = assistance_kind_group();
    $task = $group->tasks->sole();
    $task->update(TaskAssistance::attributes(AssistanceKind::Failure, null, 'The implementer thread failed.'));
    $group->update(TaskAssistance::attributes(AssistanceKind::Failure, null, 'The implementer thread failed.'));
    $question = 'May I add the missing credential?';

    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'assistance_requested',
        'body' => $question,
        'author' => 'operator',
    ]);

    expect($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question);
});

it('does not let a failure replace an open direction request', function (): void {
    $group = assistance_kind_group();
    $task = $group->tasks->sole();
    $question = 'Which database should this subtask use?';
    $reason = "The implementer is blocked: The mirror is down.\n\nQuestion: {$question}";
    $task->update(TaskAssistance::attributes(AssistanceKind::Direction, $question, $reason));
    $group->update(TaskAssistance::attributes(AssistanceKind::Direction, $question, $reason));

    expect(TaskAssistance::apply($task, AssistanceKind::Failure, null, 'The implementer thread failed.'))->toBeFalse()
        ->and(TaskAssistance::apply($group, AssistanceKind::Failure, null, 'Workspace removal failed: disk full', replaceFailure: true))->toBeFalse();
    app(RemoveTaskWorkspaceAction::class)->recordFailure($group->fresh() ?? $group, new RuntimeException('disk full'));

    expect($task->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($task->fresh()?->assistance_question)->toBe($question)
        ->and($task->fresh()?->assistance_reason)->toBe($reason)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_reason)->toBe($reason);
});

it('does not let a stale failure writer replace a direction request or keep its question', function (): void {
    $group = assistance_kind_group();
    $stale = clone $group;
    $question = 'Which database should this subtask use?';
    $reason = "The implementer is blocked: The mirror is down.\n\nQuestion: {$question}";
    DB::table('tasks')->where('id', $group->id)->update([
        'assistance_requested' => true,
        'assistance_kind' => AssistanceKind::Direction->value,
        'assistance_question' => $question,
        'assistance_reason' => $reason,
    ]);

    expect(TaskAssistance::apply($stale, AssistanceKind::Failure, null, 'Workspace removal failed: disk full', replaceFailure: true))->toBeFalse();
    app(RemoveTaskWorkspaceAction::class)->recordFailure($stale, new RuntimeException('disk full'));

    expect($stale->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($stale->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Direction)
        ->and($group->fresh()?->assistance_question)->toBe($question)
        ->and($group->fresh()?->assistance_reason)->toBe($reason);
});

it('keeps the reason without asking when a stale assistance writer targets an ended task', function (string $level, string $status, AssistanceKind $kind): void {
    $group = assistance_kind_group();
    $record = $level === 'group' ? $group : $group->tasks->sole();
    $stale = clone $record;
    DB::table('tasks')->where('id', $record->id)->update(['status' => $status]);
    $reason = 'The request arrived after the task ended.';

    $applied = TaskAssistance::apply($stale, $kind, $kind === AssistanceKind::Direction ? 'Which mirror?' : null, $reason);

    expect($applied)->toBeTrue()
        ->and($stale->status->value)->toBe($status)
        ->and($stale->assistance_requested)->toBeFalse()
        ->and($stale->assistance_reason)->toBe($reason);
})->with(['group', 'subtask'])->with(['completed', 'cancelled'])->with([AssistanceKind::Direction, AssistanceKind::Failure]);

it('posts the assistance kind and question on the Coder webhook', function (AssistanceKind $kind, ?string $question, string $reason): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake([
        'https://coder.example.test/hooks/settle' => Http::response(['ok' => true]),
    ]);
    config()->set('orbit.tasks.coder_webhook_url', 'https://coder.example.test/hooks/settle');
    config()->set('orbit.tasks.coder_webhook_secret', 'coder-secret');
    $group = assistance_kind_group();
    $group->update(TaskAssistance::attributes($kind, $question, $reason));

    app(HttpCoderSettleNotifier::class)->assistance($group->fresh() ?? $group, $reason);

    Http::assertSent(function (Request $request) use ($group, $kind, $question, $reason): bool {
        $body = $request->body();
        $timestamp = (string) now()->timestamp;

        return $request->data() === [
            'event' => 'task_group.assistance_requested',
            'task_group_id' => $group->id,
            'title' => $group->title,
            'kind' => $kind->value,
            'question' => $question,
            'reason' => $reason,
        ] && $request->hasHeader('X-Orbit-Signature', 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'coder-secret'));
    });
})->with([
    'direction' => [AssistanceKind::Direction, 'Which database should this subtask use?', 'Which database should this subtask use?'],
    'failure' => [AssistanceKind::Failure, null, 'The implementer thread failed.'],
]);
