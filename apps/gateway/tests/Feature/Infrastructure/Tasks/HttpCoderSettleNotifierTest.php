<?php

declare(strict_types=1);

use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionNextAction;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskThreadObservation;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\Tasks\HttpCoderSettleNotifier;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function coder_settle_group(): Task
{
    $project = Project::query()->create([
        'name' => 'coder-app',
        'slug' => 'coder-app',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'default_branch' => 'main',
    ]);

    return Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Settle notify',
        'brief' => 'Notify Coder after the PR opens.',
        'status' => TaskGroupStatus::Settling,
        'pr_url' => 'https://github.com/nckrtl/orbit/pull/543',
        'notify_coder' => true,
        'tokens' => 40,
        'line_diff' => 12,
        'duration_ms' => 1500,
    ]);
}

it('posts an HMAC-signed settle body to Coder', function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake([
        'https://coder.example.test/hooks/settle' => Http::response(['ok' => true]),
    ]);
    config()->set('orbit.tasks.coder_webhook_url', 'https://coder.example.test/hooks/settle');
    config()->set('orbit.tasks.coder_webhook_secret', 'coder-secret');

    $group = coder_settle_group();
    $group->update(['questions' => 5, 'escalations' => 3]);
    app(HttpCoderSettleNotifier::class)->notify($group);

    Http::assertSent(function (Request $request) use ($group): bool {
        $timestamp = (string) now()->timestamp;
        $body = $request->body();
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, 'coder-secret');

        return $request->url() === 'https://coder.example.test/hooks/settle'
            && $request->hasHeader('X-Orbit-Timestamp', $timestamp)
            && $request->hasHeader('X-Orbit-Signature', 'sha256='.$expected)
            && $request->isJson()
            && $request->data() === [
                'event' => 'task_group.settled',
                'task_group_id' => $group->id,
                'title' => 'Settle notify',
                'tokens' => 40,
                'line_diff' => 12,
                'duration_ms' => 1500,
                'questions' => 5,
                'escalations' => 3,
                'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/543',
            ]
            && ! str_contains($body, 'coder-secret');
    });
});

it('posts an HMAC-signed escalate body to Coder', function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    Http::fake([
        'https://coder.example.test/hooks/settle' => Http::response(['ok' => true]),
    ]);
    config()->set('orbit.tasks.coder_webhook_url', 'https://coder.example.test/hooks/settle');
    config()->set('orbit.tasks.coder_webhook_secret', 'coder-secret');
    $group = coder_settle_group();
    $observation = new TaskSessionObservation(
        taskId: 42,
        taskStatus: 'running',
        taskTitle: 'Models',
        taskBrief: 'Store the records.',
        groupId: $group->id,
        groupStatus: $group->status->value,
        title: $group->title,
        brief: $group->brief,
        hasPendingSubtasks: false,
        prUrl: $group->pr_url,
        ciSummary: null,
        threads: [
            new TaskThreadObservation(
                threadId: 1,
                role: TaskThreadRole::Implementer,
                sessState: 'idle',
                idle: true,
                pendingApprovalId: null,
                pendingUserInputId: null,
                lastAssistantText: 'Need a human.',
                lastUserText: null,
                hasNewCommitsSinceThreadStart: false,
                prUrl: $group->pr_url,
                ciSummary: null,
            ),
        ],
    );
    $decision = new TaskSessionDecision(TaskSessionNextAction::EscalateCoder, 0.2, 'Choice confidence 0.2 is below 0.75.');

    app(HttpCoderSettleNotifier::class)->escalate($group, $observation, $decision);

    Http::assertSent(function (Request $request) use ($group, $observation): bool {
        $timestamp = (string) now()->timestamp;
        $body = $request->body();
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, 'coder-secret');
        $payload = $request->data();

        return $request->url() === 'https://coder.example.test/hooks/settle'
            && $request->hasHeader('X-Orbit-Timestamp', $timestamp)
            && $request->hasHeader('X-Orbit-Signature', 'sha256='.$expected)
            && ($payload['event'] ?? null) === 'task_group.escalated'
            && ($payload['task_group_id'] ?? null) === $group->id
            && ($payload['reason'] ?? null) === 'Choice confidence 0.2 is below 0.75.'
            && ($payload['confidence'] ?? null) === 0.2
            && ($payload['thread_id'] ?? null) === 1
            && ($payload['observation'] ?? null) === $observation->toArray()
            && ! str_contains($body, 'coder-secret');
    });
});

it('skips the webhook when the URL or secret is missing', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    config()->set('orbit.tasks.coder_webhook_url', 'https://coder.example.test/hooks/settle');
    config()->set('orbit.tasks.coder_webhook_secret', null);

    app(HttpCoderSettleNotifier::class)->notify(coder_settle_group());

    Http::assertNothingSent();
});

it('does not fail settle when Coder refuses the webhook', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://coder.example.test/hooks/settle' => Http::response(['error' => 'no'], 500),
    ]);
    config()->set('orbit.tasks.coder_webhook_url', 'https://coder.example.test/hooks/settle');
    config()->set('orbit.tasks.coder_webhook_secret', 'coder-secret');

    expect(fn () => app(HttpCoderSettleNotifier::class)->notify(coder_settle_group()))
        ->not->toThrow(Throwable::class);
});

it('posts a direction request to OpsBot and records the headers and body', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://coder.example.test/hooks/settle' => Http::response(['ok' => true]),
        'https://opsbot.example.test/hooks/direction' => Http::response(['ok' => true]),
    ]);
    config()->set('orbit.tasks.coder_webhook_url', 'https://coder.example.test/hooks/settle');
    config()->set('orbit.tasks.coder_webhook_secret', 'coder-secret');
    config()->set('orbit.tasks.opsbot_webhook_url', 'https://opsbot.example.test/hooks/direction');
    config()->set('orbit.tasks.opsbot_webhook_secret', 'opsbot-secret');
    $group = coder_settle_group();
    $question = 'Which database should this subtask use?';
    $reason = 'The implementer is blocked: The mirror is down.';
    $group->update(TaskAssistance::attributes(AssistanceKind::Direction, $question, $reason));

    app(HttpCoderSettleNotifier::class)->assistance($group->fresh() ?? $group, $reason);

    $recorded = Http::recorded(fn (Request $request): bool => $request->url() === 'https://opsbot.example.test/hooks/direction');
    expect($recorded)->toHaveCount(1);
    [$request] = $recorded->first();
    expect($request->hasHeader('Content-Type', 'application/json'))->toBeTrue()
        ->and($request->hasHeader('Authorization', 'Bearer opsbot-secret'))->toBeTrue()
        ->and($request->hasHeader('X-Automation-Key', 'opsbot-secret'))->toBeTrue()
        ->and($request->isJson())->toBeTrue()
        ->and($request->data())->toBe([
            'event' => 'task_group.assistance_requested',
            'task_group_id' => $group->id,
            'title' => 'Settle notify',
            'kind' => 'direction',
            'question' => $question,
            'reason' => $reason,
        ])
        ->and($request->body())->not->toContain('opsbot-secret');
});

it('does not post settle, escalate, or non-direction assistance to OpsBot', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://coder.example.test/hooks/settle' => Http::response(['ok' => true]),
        'https://opsbot.example.test/hooks/direction' => Http::response(['ok' => true]),
    ]);
    config()->set('orbit.tasks.coder_webhook_url', 'https://coder.example.test/hooks/settle');
    config()->set('orbit.tasks.coder_webhook_secret', 'coder-secret');
    config()->set('orbit.tasks.opsbot_webhook_url', 'https://opsbot.example.test/hooks/direction');
    config()->set('orbit.tasks.opsbot_webhook_secret', 'opsbot-secret');
    $group = coder_settle_group();
    $notifier = app(HttpCoderSettleNotifier::class);
    $observation = new TaskSessionObservation(
        taskId: 42,
        taskStatus: 'running',
        taskTitle: 'Models',
        taskBrief: 'Store the records.',
        groupId: $group->id,
        groupStatus: $group->status->value,
        title: $group->title,
        brief: $group->brief,
        hasPendingSubtasks: false,
        prUrl: $group->pr_url,
        ciSummary: null,
        threads: [
            new TaskThreadObservation(
                threadId: 1,
                role: TaskThreadRole::Implementer,
                sessState: 'idle',
                idle: true,
                pendingApprovalId: null,
                pendingUserInputId: null,
                lastAssistantText: 'Need a human.',
                lastUserText: null,
                hasNewCommitsSinceThreadStart: false,
                prUrl: $group->pr_url,
                ciSummary: null,
            ),
        ],
    );
    $decision = new TaskSessionDecision(TaskSessionNextAction::EscalateCoder, 0.2, 'Choice confidence 0.2 is below 0.75.');

    $notifier->notify($group);
    $notifier->escalate($group, $observation, $decision);
    $group->update(TaskAssistance::attributes(AssistanceKind::Failure, null, 'The implementer thread failed.'));
    $notifier->assistance($group->fresh() ?? $group, 'The implementer thread failed.');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://opsbot.example.test/hooks/direction');
    Http::assertSentCount(3);
});

it('skips the OpsBot post when the URL or secret is missing', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://coder.example.test/hooks/settle' => Http::response(['ok' => true]),
    ]);
    config()->set('orbit.tasks.coder_webhook_url', 'https://coder.example.test/hooks/settle');
    config()->set('orbit.tasks.coder_webhook_secret', 'coder-secret');
    config()->set('orbit.tasks.opsbot_webhook_url', 'https://opsbot.example.test/hooks/direction');
    config()->set('orbit.tasks.opsbot_webhook_secret', null);
    $group = coder_settle_group();
    $group->update(TaskAssistance::attributes(AssistanceKind::Direction, 'Which database?', 'Which database?'));

    app(HttpCoderSettleNotifier::class)->assistance($group->fresh() ?? $group, 'Which database?');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://coder.example.test/hooks/settle');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://opsbot.example.test/hooks/direction');
});
