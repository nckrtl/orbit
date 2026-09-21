<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentThreadState;
use App\Infrastructure\Tasks\T3\T3Projection;

it('normalizes runtime states without collapsing completion or failure into idle', function (array $thread, ?AgentThreadState $expected): void {
    $observed = new T3Projection()->observe(['thread' => $thread]);
    expect($observed->state)->toBe($expected);
})->with([
    'idle' => [['session' => ['status' => 'idle']], AgentThreadState::Idle],
    'working' => [['session' => ['status' => 'running']], AgentThreadState::Working],
    'input' => [['session' => ['status' => 'running'], 'pendingUserInputs' => [['requestId' => 'q']]], AgentThreadState::AskingForInput],
    'done' => [['session' => ['status' => 'done']], AgentThreadState::Done],
    'completed turn' => [['session' => ['status' => 'idle'], 'latestTurn' => ['state' => 'completed']], AgentThreadState::Done],
    'failed' => [['session' => ['status' => 'failed']], AgentThreadState::Failed],
    'error' => [['session' => ['status' => 'error']], AgentThreadState::Failed],
    'unknown' => [[], null],
]);

it('ignores resolved requests and requests belonging to older turns', function (): void {
    $thread = [
        'session' => ['status' => 'running'], 'latestTurn' => ['id' => 'new', 'state' => 'running'],
        'activities' => [
            ['kind' => 'user-input', 'payload' => ['requestId' => 'old', 'turnId' => 'old']],
            ['kind' => 'approval', 'payload' => ['requestId' => 'resolved', 'turnId' => 'new']],
            ['kind' => 'approval.responded', 'payload' => ['requestId' => 'resolved', 'turnId' => 'new']],
            ['kind' => 'user-input', 'payload' => ['requestId' => 'current', 'turnId' => 'new', 'questions' => ['Continue?']]],
        ],
    ];
    $observation = new T3Projection()->observe(['thread' => $thread]);
    expect($observation->state)->toBe(AgentThreadState::AskingForInput)
        ->and($observation->inputRequests)->toHaveCount(1)
        ->and($observation->inputRequests[0]->id)->toBe('current')
        ->and($observation->inputRequests[0]->details['questions'])->toBe(['Continue?']);
});

it('retains a terminal outcome until new work starts and ignores historical input', function (AgentThreadState $state): void {
    $projection = new T3Projection;
    $idle = $projection->observe(['thread' => ['session' => ['status' => 'idle'], 'activities' => [['kind' => 'approval', 'payload' => ['requestId' => 'old']]]]], $state);
    expect($idle->state)->toBe($state)->and($idle->inputRequests)->toBe([]);
    $working = $projection->observe(['thread' => ['session' => ['status' => 'running']]], $state);
    expect($working->state)->toBe(AgentThreadState::Working);
})->with([AgentThreadState::Done, AgentThreadState::Failed]);

it('keeps missing metrics unknown and returns failure details', function (): void {
    $observation = new T3Projection()->observe(['thread' => ['session' => ['status' => 'failed', 'lastError' => 'Model refused the request.']]]);
    expect($observation->tokens)->toBeNull()
        ->and($observation->linesAdded)->toBeNull()
        ->and($observation->error)->toBe('Model refused the request.');
});

it('preserves failure details when an idle snapshot has no new outcome', function (): void {
    $observation = new T3Projection()->observe(['thread' => ['session' => ['status' => 'idle']]], AgentThreadState::Failed, 'Model refused the request.');
    expect($observation->state)->toBe(AgentThreadState::Failed)
        ->and($observation->error)->toBe('Model refused the request.');
});

it('projects T3 session completion and checkpoint events into a completed turn', function (): void {
    $projection = new T3Projection;
    $thread = ['session' => ['status' => 'running', 'activeTurnId' => 'turn-1'], 'latestTurn' => ['turnId' => 'turn-1', 'state' => 'running']];
    $thread = $projection->apply($thread, ['type' => 'thread.session-set', 'payload' => ['session' => ['status' => 'ready', 'activeTurnId' => null, 'updatedAt' => '2026-09-22T12:00:00Z']]]);
    expect($projection->observe(['thread' => $thread])->state)->toBe(AgentThreadState::Done);
    $event = ['type' => 'thread.turn-diff-completed', 'payload' => ['turnId' => 'turn-1', 'status' => 'ready', 'files' => [['additions' => 4, 'deletions' => 2]]]];
    $thread = $projection->apply($thread, $event);
    $thread = $projection->apply($thread, $event);
    $observation = $projection->observe(['thread' => $thread]);
    expect($observation->state)->toBe(AgentThreadState::Done)
        ->and($observation->linesAdded)->toBe(4)
        ->and($observation->linesDeleted)->toBe(2);
});

it('appends T3 streaming message deltas and preserves text on an empty completion', function (): void {
    $projection = new T3Projection;
    $thread = ['messages' => [['id' => 'm', 'role' => 'assistant', 'text' => 'Hello', 'createdAt' => '2026-09-22T12:00:00Z']]];
    foreach ([['text' => ' world', 'streaming' => true], ['text' => '', 'streaming' => false]] as $part) {
        $thread = $projection->apply($thread, ['type' => 'thread.message-sent', 'payload' => ['messageId' => 'm', 'role' => 'assistant', ...$part]]);
    }
    $observed = $projection->observe(['thread' => $thread]);
    expect($observed->entries)->toHaveCount(1)
        ->and($observed->lastText('assistant'))->toBe('Hello world');
});
