<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskThreadObservation;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\Tasks\LaravelAiTaskSessionClassifier;

/**
 * Builds an observation from the array the Coder escalation webhook posts.
 *
 * @param  array<string, mixed>  $data
 */
function calibration_observation(array $data): TaskSessionObservation
{
    return new TaskSessionObservation(
        groupId: $data['group_id'],
        taskId: $data['task_id'],
        taskStatus: $data['task_status'],
        taskTitle: $data['task_title'],
        taskBrief: $data['task_brief'],
        groupStatus: $data['group_status'],
        title: $data['title'],
        brief: $data['brief'],
        hasPendingSubtasks: $data['has_pending_subtasks'],
        prUrl: $data['pr_url'],
        ciSummary: $data['ci_summary'],
        threads: array_map(static fn (array $thread): TaskThreadObservation => new TaskThreadObservation(
            threadId: $thread['thread_id'],
            role: TaskThreadRole::from($thread['role']),
            sessState: $thread['state'],
            idle: $thread['idle'],
            pendingApprovalId: $thread['pending_approval_id'],
            pendingUserInputId: $thread['pending_user_input_id'],
            lastAssistantText: $thread['last_assistant_text'],
            lastUserText: $thread['last_user_text'],
            hasNewCommitsSinceThreadStart: $thread['has_new_commits_since_thread_start'],
            prUrl: $thread['pr_url'],
            ciSummary: $thread['ci_summary'],
            available: $thread['available'],
            error: $thread['error'],
            recentMessages: $thread['recent_messages'],
        ), $data['threads']),
        available: $data['available'],
    );
}

/** @return array<string, array{string}> */
function calibration_fixtures(): array
{
    $fixtures = [];
    foreach (glob(dirname(__DIR__).'/Fixtures/JevCalibration/*.json') ?: [] as $path) {
        $fixtures[basename($path, '.json')] = [$path];
    }

    return $fixtures;
}

beforeEach(function (): void {
    $key = config('ai.providers.typesafe.key');
    if (! is_string($key) || $key === '') {
        throw new RuntimeException('Set TYPESAFE_API_KEY to run the Jev calibration suite.');
    }
});

it('answers the blocked question from real Jev as calibrated', function (string $path): void {
    /** @var array{role: string, expected: string, observation: array<string, mixed>} $fixture */
    $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $threshold = (float) config('orbit.tasks.jev_confidence_threshold', 0.75);

    $check = app(LaravelAiTaskSessionClassifier::class)->classifyTranscript(
        calibration_observation($fixture['observation']),
        TaskThreadRole::from($fixture['role']),
    )['blocked'];

    fwrite(STDERR, sprintf(
        "%s: expected %s, Jev answered %s at %.3f, probabilities %s, threshold %.2f\n",
        basename($path, '.json'),
        $fixture['expected'],
        $check->choice,
        $check->confidence,
        json_encode($check->probabilities, JSON_THROW_ON_ERROR),
        $threshold,
    ));

    expect($check->choice)->toBe($fixture['expected']);
    if ($fixture['expected'] === 'no') {
        expect($check->confidence)->toBeGreaterThanOrEqual($threshold);
    }
})->with(calibration_fixtures());
