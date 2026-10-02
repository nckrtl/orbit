<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskReviewContext;
use App\Domain\Tasks\TaskReviewPacket;

it('keeps every part the review packet cuts', function (): void {
    $taskBrief = "it's \$HOME\n".str_repeat('é', 2_100)."\nGROUP-END";
    $subtaskBrief = str_repeat('S', 2_100)."\nSUBTASK-END";
    $description = str_repeat('D', 400)."\nDELIVERABLE-END";
    $command = str_repeat('C', 300).'COMMAND-END';
    $path = 'apps/gateway/'.str_repeat('p', 200).'PATH-END.php';
    $approval = str_repeat('A', 400)."\nAPPROVAL-END";
    $resolution = str_repeat('R', 2_100)."\nRESOLUTION-END";
    $consults = [['question' => str_repeat('Q', 500).'QUESTION-END', 'answer' => str_repeat('A', 500).'ANSWER-END']];
    $deliverable = TaskDeliverable::fromArray([
        'id' => 'context-file',
        'type' => 'command',
        'description' => $description,
        'command' => $command,
        'directory' => 'apps/gateway',
        'fails_on_base' => true,
        'paths' => [$path],
    ]);
    $packet = new TaskReviewPacket(
        groupBrief: $taskBrief,
        subtaskId: 7,
        subtaskTitle: 'Write the context',
        subtaskBrief: $subtaskBrief,
        deliverables: [$deliverable],
        approvals: [['title' => 'Earlier work', 'summary' => $approval]],
        diffFiles: [],
        diff: '',
        taskCheck: null,
        handoffStatus: 'passed',
        handoffExitCode: null,
        evidence: null,
        startCommit: str_repeat('a', 40),
        resolution: $resolution,
        consults: $consults,
    )->render();
    $context = new TaskReviewContext(
        taskBrief: $taskBrief,
        subtaskBrief: $subtaskBrief,
        deliverables: [$deliverable],
        approvals: [['title' => 'Earlier work', 'body' => $approval]],
        resolution: $resolution,
        consults: $consults,
    )->render();

    expect($packet)->not->toContain('GROUP-END')
        ->and($packet)->not->toContain('SUBTASK-END')
        ->and($packet)->not->toContain('DELIVERABLE-END')
        ->and($packet)->not->toContain('COMMAND-END')
        ->and($packet)->not->toContain('PATH-END')
        ->and($packet)->not->toContain('APPROVAL-END')
        ->and($packet)->not->toContain('RESOLUTION-END')
        ->and($packet)->not->toContain('QUESTION-END')
        ->and($packet)->not->toContain('ANSWER-END')
        ->and($packet)->toContain(TaskReviewContext::Path)
        ->and($context)->toContain($taskBrief)
        ->and($context)->toContain($subtaskBrief)
        ->and($context)->toContain($description)
        ->and($context)->toContain($command)
        ->and($context)->toContain($path)
        ->and($context)->toContain('fails_on_base: true')
        ->and($context)->toContain($approval)
        ->and($context)->toContain($resolution)
        ->and($context)->toContain($consults[0]['question'])
        ->and($context)->toContain($consults[0]['answer'])
        ->and($context)->toContain('### context-file')
        ->and($context)->toContain('- id: context-file')
        ->and($context)->toContain('- directory: apps/gateway');
});

it('names an empty deliverable list, approval list, and resolution', function (): void {
    $context = new TaskReviewContext(
        taskBrief: 'Ship it.',
        subtaskBrief: 'Write the file.',
        deliverables: [],
        approvals: [],
        resolution: '',
    )->render();

    expect($context)->toContain("## Deliverables\n\nNone.")
        ->and($context)->toContain("## Earlier approvals\n\nNone.")
        ->and($context)->toContain("## Held resolution\n\nNone.")
        ->and($context)->toContain("## Answered consults\n\nNone.")
        ->and($context)->toContain("## Task brief\n\nShip it.")
        ->and($context)->toContain("## Subtask brief\n\nWrite the file.");
});
