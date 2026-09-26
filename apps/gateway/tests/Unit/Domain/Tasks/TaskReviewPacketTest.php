<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskDeliverableEvidence;
use App\Domain\Tasks\TaskReviewPacket;
use App\Domain\Tasks\TaskRunInstructions;

it('renders a review packet with the group brief, subtask brief, deliverables, approvals, diff stat, handoff, and diff', function (): void {
    $packet = review_packet();

    expect(packet_section($packet, 'Group brief'))->toBe('Ship the export.')
        ->and(packet_section($packet, 'Subtask brief'))->toBe('Return the packet as text within the size limits.')
        ->and(packet_section($packet, 'Deliverables'))->toBe(implode("\n", [
            '- reference-page (file): Document the export',
            '- layout-repro (test): The home-screen test fails before the fix',
            '- web-tests (command): The web app tests pass',
            '- error-copy (review): Error messages name the failing subtask',
        ]))
        ->and(packet_section($packet, 'Earlier approved subtasks'))->toBe('- Decide the packet: ADR 0169 records the caps.')
        ->and(packet_section($packet, 'Diff stat'))->toBe(implode("\n", [
            '2 files changed, 14 insertions(+), 1 deletion(-)',
            'apps/gateway/app/Domain/Tasks/TaskReviewPacket.php',
            'notes/untracked.txt',
        ]))
        ->and(packet_section($packet, 'Handoff'))->toBe(implode("\n", [
            'Status: passed',
            '`composer check` in . exited 0',
            '`vendor/bin/pest tests/Feature/HomeScreenTest.php` in apps/gateway exited 2 on the start commit with an error and a failure: Class "HomeScreen" not found; Failed asserting that 1 is 2.',
            '`vendor/bin/pest tests/Feature/HomeScreenTest.php` in apps/gateway exited 0',
            '`bun test` in apps/web exited 0',
        ]))
        ->and(packet_section($packet, 'Diff'))->toBe("diff --git a/packet.php b/packet.php\n+packet")
        ->and($packet)->toContain('The approval must confirm each review deliverable (error-copy) with --deliverable=ID=evidence')
        ->and($packet)->not->toContain('--pr-summary');
});

it('cuts the packet group brief and subtask brief from the end and names tasks-show', function (): void {
    $packet = review_packet([
        'groupBrief' => str_repeat('é', 2_100).'GROUP-END',
        'subtaskBrief' => str_repeat('b', 2_500).'SUBTASK-END',
    ]);
    $group = packet_section($packet, 'Group brief');
    $subtask = packet_section($packet, 'Subtask brief');
    $note = 'The end is cut. tasks-show returns the full brief.';

    expect(mb_strlen($group))->toBe(TaskReviewPacket::BriefLimit)
        ->and($group)->toStartWith(str_repeat('é', 1_500))
        ->and($group)->not->toContain('GROUP-END')
        ->and($group)->toEndWith($note)
        ->and(mb_strlen($subtask))->toBe(TaskReviewPacket::BriefLimit)
        ->and($subtask)->toStartWith(str_repeat('b', 1_500))
        ->and($subtask)->not->toContain('SUBTASK-END')
        ->and($subtask)->toEndWith($note);
});

it('keeps each packet deliverable line within 240 characters and drops lines that do not fit', function (): void {
    $deliverables = [];
    for ($index = 1; $index <= 20; $index++) {
        $deliverables[] = TaskDeliverable::fromArray([
            'id' => 'item-'.$index,
            'type' => 'review',
            'description' => 'item-'.$index.' '.str_repeat('d', 400),
        ]);
    }
    $body = packet_section(review_packet(['deliverables' => $deliverables]), 'Deliverables');
    $lines = explode("\n", $body);
    $kept = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, '- ')));

    expect(mb_strlen($body))->toBeLessThanOrEqual(TaskReviewPacket::DeliverablesLimit)
        ->and($kept)->not->toBeEmpty()
        ->and($kept[0])->toStartWith('- item-1 (review): item-1 ')
        ->and(mb_strlen($kept[0]))->toBeLessThanOrEqual(TaskReviewPacket::DeliverableLineLimit)
        ->and(mb_strlen(substr($kept[0], strpos($kept[0], ': ') + 2)))->toBeLessThanOrEqual(TaskReviewPacket::DescriptionLimit)
        ->and($body)->not->toContain('item-20')
        ->and($body)->toEndWith((20 - count($kept)).' deliverables were omitted. tasks-show returns every field.');
});

it('drops the oldest packet approval lines first and keeps each line within 200 characters', function (): void {
    $approvals = [];
    for ($index = 1; $index <= 12; $index++) {
        $approvals[] = [
            'title' => 'Task '.$index.' '.str_repeat('t', 80),
            'summary' => 'summary-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).' '.str_repeat('s', 180),
        ];
    }
    $body = packet_section(review_packet(['approvals' => $approvals]), 'Earlier approved subtasks');
    $lines = explode("\n", $body);
    $kept = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, '- ')));

    expect(mb_strlen($body))->toBeLessThanOrEqual(TaskReviewPacket::ApprovalsLimit)
        ->and($lines[0])->toBe((12 - count($kept)).' earlier approvals were omitted. tasks-comment-list returns each approval summary.')
        ->and($kept)->not->toBeEmpty()
        ->and($body)->not->toContain('summary-01')
        ->and($kept[array_key_last($kept)])->toContain('summary-12')
        ->and(max(array_map(mb_strlen(...), $kept)))->toBeLessThanOrEqual(TaskReviewPacket::ApprovalLineLimit);
});

it('summarizes the packet diff stat, including untracked files, and omits paths that do not fit', function (): void {
    $files = [
        ['path' => 'kept.php', 'insertions' => 3, 'deletions' => 1],
        ['path' => 'notes/untracked.txt', 'insertions' => 8, 'deletions' => 0],
    ];
    for ($index = 1; $index <= 40; $index++) {
        $files[] = ['path' => sprintf('dropped/file-%02d-%s.php', $index, str_repeat('p', 70)), 'insertions' => 1, 'deletions' => 2];
    }
    $body = packet_section(review_packet(['diffFiles' => $files]), 'Diff stat');
    $lines = explode("\n", $body);
    $paths = array_values(array_filter(
        $lines,
        static fn (string $line): bool => $line !== $lines[0] && ! str_contains($line, 'omitted'),
    ));

    expect($lines[0])->toBe('42 files changed, 51 insertions(+), 81 deletions(-)')
        ->and(mb_strlen($body))->toBeLessThanOrEqual(TaskReviewPacket::DiffStatLimit)
        ->and($paths[0])->toBe('kept.php')
        ->and($body)->toContain('notes/untracked.txt')
        ->and($body)->not->toContain('file-40-')
        ->and($lines[array_key_last($lines)])->toBe((42 - count($paths)).' paths were omitted. The stat command prints the rest.')
        ->and(packet_section(review_packet(['diffFiles' => $files]), 'Retrieval'))->toContain('git diff --stat '.str_repeat('a', 40));
});

it('lists packet handoff commands with their directory and exit code and keeps a base-run failure kind', function (): void {
    $handoff = packet_section(review_packet(), 'Handoff');

    expect($handoff)->toContain('`composer check` in . exited 0')
        ->and($handoff)->toContain('`vendor/bin/pest tests/Feature/HomeScreenTest.php` in apps/gateway exited 2 on the start commit with an error and a failure: Class "HomeScreen" not found')
        ->and($handoff)->toContain('`bun test` in apps/web exited 0')
        ->and($handoff)->not->toContain('reference-page')
        ->and($handoff)->not->toContain('.git/orbit/check.log');
});

it('cuts a packet handoff command to 160 characters and drops lines that do not fit', function (): void {
    $deliverables = [];
    $commands = [];
    for ($index = 1; $index <= 16; $index++) {
        $command = 'run-'.$index.' '.str_repeat('c', 180);
        $deliverables[] = TaskDeliverable::fromArray([
            'id' => 'command-'.$index,
            'type' => 'command',
            'description' => 'Run check '.$index,
            'command' => $command,
            'directory' => 'apps/web',
        ]);
        $commands['command-'.$index] = ['exit_code' => $index, 'output' => ''];
    }
    $body = packet_section(review_packet([
        'deliverables' => $deliverables,
        'evidence' => TaskDeliverableEvidence::fromArray(['diff' => [], 'tests' => [], 'commands' => $commands]),
        'taskCheck' => null,
        'handoffExitCode' => null,
    ]), 'Handoff');
    $lines = explode("\n", $body);
    $kept = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, '`')));

    expect(mb_strlen($body))->toBeLessThanOrEqual(TaskReviewPacket::HandoffLimit)
        ->and($lines[0])->toBe('Status: passed')
        ->and($kept[0])->toStartWith('`'.mb_substr('run-1 '.str_repeat('c', 180), 0, TaskReviewPacket::CommandLimit).'` in apps/web exited 1')
        ->and($kept[0])->not->toContain(str_repeat('c', TaskReviewPacket::CommandLimit))
        ->and($body)->not->toContain('run-16')
        ->and($lines[array_key_last($lines)])->toBe((16 - count($kept)).' commands were omitted. .git/orbit/check.log holds the command text and any cut tail. .git/orbit/check.json stores the exit codes.');
});

it('includes a packet base-run message tail only while it fits in the handoff cap', function (): void {
    $tail = str_repeat('m', 3_000);
    $fits = packet_section(review_packet(), 'Handoff');
    $cut = packet_section(review_packet([
        'evidence' => handoff_evidence(baseMessage: $tail),
    ]), 'Handoff');

    expect($fits)->toContain('Class "HomeScreen" not found')
        ->and($fits)->not->toContain('.git/orbit/check.log')
        ->and($cut)->toContain('with an error and a failure')
        ->and($cut)->not->toContain($tail)
        ->and(mb_strlen($cut))->toBeLessThanOrEqual(TaskReviewPacket::HandoffLimit)
        ->and($cut)->toContain('.git/orbit/check.log holds the command text and any cut tail.');
});

it('caps the packet diff and names the follow-up command that prints the rest', function (): void {
    $start = str_repeat('b', 40);
    $packet = review_packet([
        'startCommit' => $start,
        'diff' => str_repeat('D', 20_000).'DIFF-END',
    ]);
    $diff = packet_section($packet, 'Diff');
    $command = 'git diff '.$start.'; git ls-files --others --exclude-standard -z | while IFS= read -r -d \'\' path; do git diff --no-index -- /dev/null "$path" || true; done';

    expect($diff)->toStartWith(str_repeat('D', 100))
        ->and($diff)->not->toContain('DIFF-END')
        ->and($diff)->toEndWith('The end of the diff is cut. The diff command prints the rest, including the content of untracked files.')
        ->and(strlen(strtok($diff, "\n")))->toBeLessThanOrEqual(TaskReviewPacket::DiffBytes)
        ->and(packet_section($packet, 'Retrieval'))->toContain($command)
        ->and($packet)->not->toContain('git add')
        ->and(mb_strlen(packet_section($packet, 'Retrieval')))->toBeLessThanOrEqual(TaskReviewPacket::RetrievalLimit);
});

it('stops the packet diff body at 16384 bytes', function (): void {
    $packet = review_packet([
        'groupBrief' => 'Short.',
        'subtaskBrief' => 'Short.',
        'deliverables' => [],
        'approvals' => [],
        'diffFiles' => [],
        'diff' => str_repeat('あ', 20_000),
        'taskCheck' => null,
        'handoffExitCode' => null,
        'evidence' => null,
    ]);
    $body = strtok(packet_section($packet, 'Diff'), "\n");

    expect(mb_strlen($packet))->toBeLessThanOrEqual(TaskReviewPacket::Limit)
        ->and(strlen($body))->toBeLessThanOrEqual(TaskReviewPacket::DiffBytes)
        ->and(strlen($body))->toBeGreaterThan(16_000)
        ->and($packet)->toContain('The diff command prints the rest, including the content of untracked files.');
});

it('keeps the whole review packet within 16000 characters', function (): void {
    $deliverables = [];
    $commands = [];
    for ($index = 1; $index <= 12; $index++) {
        $deliverables[] = TaskDeliverable::fromArray([
            'id' => 'command-'.$index,
            'type' => 'command',
            'description' => str_repeat('d', 500),
            'command' => str_repeat('c', 300),
            'directory' => 'apps/gateway',
        ]);
        $commands['command-'.$index] = ['exit_code' => 0, 'output' => str_repeat('o', 4_000)];
    }
    $approvals = [];
    for ($index = 1; $index <= 15; $index++) {
        $approvals[] = ['title' => 'Task '.$index, 'summary' => str_repeat('s', 400)];
    }
    $files = [];
    for ($index = 1; $index <= 40; $index++) {
        $files[] = ['path' => 'src/file-'.$index.'.php', 'insertions' => 10, 'deletions' => 4];
    }
    $packet = review_packet([
        'groupBrief' => str_repeat('g', 5_000),
        'subtaskTitle' => str_repeat('T', 255),
        'subtaskBrief' => str_repeat('b', 5_000),
        'deliverables' => $deliverables,
        'approvals' => $approvals,
        'diffFiles' => $files,
        'diff' => str_repeat('D', 30_000),
        'evidence' => TaskDeliverableEvidence::fromArray(['diff' => [], 'tests' => [], 'commands' => $commands]),
        'opensPullRequest' => true,
    ]);

    expect(mb_strlen($packet))->toBeLessThanOrEqual(TaskReviewPacket::Limit)
        ->and(mb_strlen(packet_section($packet, 'Group brief')))->toBeLessThanOrEqual(TaskReviewPacket::BriefLimit)
        ->and(mb_strlen(packet_section($packet, 'Subtask brief')))->toBeLessThanOrEqual(TaskReviewPacket::BriefLimit)
        ->and(mb_strlen(packet_section($packet, 'Deliverables')))->toBeLessThanOrEqual(TaskReviewPacket::DeliverablesLimit)
        ->and(mb_strlen(packet_section($packet, 'Earlier approved subtasks')))->toBeLessThanOrEqual(TaskReviewPacket::ApprovalsLimit)
        ->and(mb_strlen(packet_section($packet, 'Diff stat')))->toBeLessThanOrEqual(TaskReviewPacket::DiffStatLimit)
        ->and(mb_strlen(packet_section($packet, 'Handoff')))->toBeLessThanOrEqual(TaskReviewPacket::HandoffLimit)
        ->and(strlen(strtok(packet_section($packet, 'Diff'), "\n")))->toBeLessThanOrEqual(TaskReviewPacket::DiffBytes)
        ->and(mb_strlen(packet_section($packet, 'Retrieval')))->toBeLessThanOrEqual(TaskReviewPacket::RetrievalLimit)
        ->and($packet)->toContain('|| true; done')
        ->and($packet)->toContain('--pr-summary');
});

it('gives characters spared by omitted packet sections to the diff on a continued turn', function (): void {
    $shared = [
        'groupBrief' => str_repeat('g', 2_000),
        'subtaskBrief' => str_repeat('b', 2_000),
        'deliverables' => array_map(
            static fn (int $index): TaskDeliverable => TaskDeliverable::fromArray([
                'id' => 'item-'.$index,
                'type' => 'review',
                'description' => str_repeat('d', 180),
            ]),
            range(1, 8),
        ),
        'approvals' => array_map(
            static fn (int $index): array => ['title' => 'Task '.$index, 'summary' => str_repeat('s', 160)],
            range(1, 6),
        ),
        'diff' => str_repeat('x', 20_000),
    ];
    $full = review_packet($shared);
    $continued = review_packet([...$shared, 'continued' => true]);
    $fullDiff = strtok(packet_section($full, 'Diff'), "\n");
    $continuedDiff = strtok(packet_section($continued, 'Diff'), "\n");

    expect($full)->not->toContain(str_repeat('x', 20_000))
        ->and(str_starts_with($continuedDiff, $fullDiff))->toBeTrue()
        ->and(strlen($continuedDiff))->toBeGreaterThan(strlen($fullDiff) + 4_000)
        ->and($continued)->not->toContain('Group brief')
        ->and($continued)->not->toContain('Deliverables')
        ->and($continued)->not->toContain('Earlier approved subtasks')
        ->and($continued)->toContain('Subtask brief')
        ->and($continued)->toContain('Do not re-run the Project task check')
        ->and(mb_strlen($continued))->toBeLessThanOrEqual(TaskReviewPacket::Limit);
});

it('tells the packet reviewer not to re-run passed checks and that the turn is read-only', function (): void {
    $composer = review_packet();
    $other = review_packet(['taskCheck' => 'vp run check']);
    $none = review_packet(['taskCheck' => null, 'handoffExitCode' => null]);

    expect($composer)->toContain('Do not re-run the Project task check or the deliverable tests and commands the handoff already passed. That includes `composer check`.')
        ->and($composer)->toContain('The implementer works with a minimal toolset and has no web access.')
        ->and($composer)->toContain('current documentation for the versions this Project uses.')
        ->and($composer)->toContain('This review is read-only. Do not create, edit, reset, or delete workspace files')
        ->and($composer)->toContain('--outcome=approved')
        ->and($composer)->toContain('--outcome=changes_requested')
        ->and($other)->toContain('The Project task check is `vp run check`.')
        ->and($other)->not->toContain('That includes `composer check`.')
        ->and($none)->not->toContain('`composer check`')
        ->and($none)->toContain('Do not re-run the Project task check');
});

it('keeps a review packet within 16000 characters when the task check and every stored field are at their maximum', function (): void {
    $taskCheck = 'vp-run-'.str_repeat('c', 4_096 - strlen('vp-run-'));
    $deliverables = [];
    $commands = [];
    for ($index = 1; $index <= 5; $index++) {
        $id = str_pad('cmd-'.$index.'-', 64, 'a');
        $deliverables[] = TaskDeliverable::fromArray([
            'id' => $id,
            'type' => 'command',
            'description' => str_repeat('d', 500),
            'command' => str_repeat('m', 1_000),
            'directory' => str_repeat('p', 500),
        ]);
        $commands[$id] = ['exit_code' => 0, 'output' => ''];
    }
    $approvals = [];
    for ($index = 1; $index <= 10; $index++) {
        $approvals[] = ['title' => str_repeat('t', 160), 'summary' => 'approval-'.$index.' '.str_repeat('s', 500)];
    }
    $files = [];
    for ($index = 1; $index <= 40; $index++) {
        $files[] = ['path' => sprintf('src/file-%02d-%s.php', $index, str_repeat('f', 80)), 'insertions' => 20, 'deletions' => 5];
    }
    $start = str_repeat('a', 40);
    $packet = review_packet([
        'groupBrief' => str_repeat('g', 8_000),
        'subtaskTitle' => str_repeat('T', 160),
        'subtaskBrief' => str_repeat('b', 8_000),
        'deliverables' => $deliverables,
        'approvals' => $approvals,
        'diffFiles' => $files,
        'diff' => str_repeat('D', 30_000),
        'taskCheck' => $taskCheck,
        'evidence' => TaskDeliverableEvidence::fromArray(['diff' => [], 'tests' => [], 'commands' => $commands]),
        'startCommit' => $start,
        'opensPullRequest' => true,
    ]);
    $shown = mb_substr($taskCheck, 0, TaskReviewPacket::CommandLimit);
    $retrieval = <<<BASH
        git diff --stat {$start}; git ls-files --others --exclude-standard -z | while IFS= read -r -d '' path; do git diff --no-index --stat -- /dev/null "\$path" || true; done
        git diff {$start}; git ls-files --others --exclude-standard -z | while IFS= read -r -d '' path; do git diff --no-index -- /dev/null "\$path" || true; done
        BASH;

    expect(mb_strlen($packet))->toBeLessThanOrEqual(TaskReviewPacket::Limit)
        ->and($packet)->not->toContain($taskCheck)
        ->and($packet)->toContain('The Project task check is `'.$shown.'`. .git/orbit/check.log holds the rest.')
        ->and($packet)->not->toContain(mb_substr($taskCheck, 0, TaskReviewPacket::CommandLimit + 1))
        ->and($packet)->toContain($retrieval)
        ->and($packet)->toContain(TaskRunInstructions::reviewer(final: true))
        ->and(mb_strlen(packet_section($packet, 'Group brief')))->toBeLessThanOrEqual(TaskReviewPacket::BriefLimit)
        ->and(mb_strlen(packet_section($packet, 'Subtask brief')))->toBeLessThanOrEqual(TaskReviewPacket::BriefLimit)
        ->and(mb_strlen(packet_section($packet, 'Deliverables')))->toBeLessThanOrEqual(TaskReviewPacket::DeliverablesLimit)
        ->and(mb_strlen(packet_section($packet, 'Earlier approved subtasks')))->toBeLessThanOrEqual(TaskReviewPacket::ApprovalsLimit)
        ->and(mb_strlen(packet_section($packet, 'Diff stat')))->toBeLessThanOrEqual(TaskReviewPacket::DiffStatLimit)
        ->and(mb_strlen(packet_section($packet, 'Handoff')))->toBeLessThanOrEqual(TaskReviewPacket::HandoffLimit)
        ->and(mb_strlen(packet_section($packet, 'Retrieval')))->toBeLessThanOrEqual(TaskReviewPacket::RetrievalLimit);
});

it('keeps the packet retrieval commands and closing instructions when earlier text would pass 16000 characters', function (): void {
    $start = str_repeat('a', 40);
    $packet = review_packet([
        'subtaskTitle' => str_repeat('T', 20_000),
        'deliverables' => [],
        'taskCheck' => null,
        'handoffExitCode' => null,
        'startCommit' => $start,
        'opensPullRequest' => true,
    ]);

    expect(mb_strlen($packet))->toBeLessThanOrEqual(TaskReviewPacket::Limit)
        ->and($packet)->toEndWith(TaskRunInstructions::reviewer(final: true))
        ->and($packet)->toContain('git diff '.$start.'; git ls-files --others --exclude-standard -z | while IFS= read -r -d \'\' path; do git diff --no-index -- /dev/null "$path" || true; done');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function review_packet(array $overrides = []): string
{
    $values = [
        'groupBrief' => 'Ship the export.',
        'subtaskId' => 241,
        'subtaskTitle' => 'Build the review packet',
        'subtaskBrief' => 'Return the packet as text within the size limits.',
        'deliverables' => [
            TaskDeliverable::fromArray(['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/tasks.md', 'change' => 'modified']),
            TaskDeliverable::fromArray(['id' => 'layout-repro', 'type' => 'test', 'description' => 'The home-screen test fails before the fix', 'project' => 'apps/gateway', 'file' => 'tests/Feature/HomeScreenTest.php', 'name' => 'home screen layout', 'fails_on_base' => true]),
            TaskDeliverable::fromArray(['id' => 'web-tests', 'type' => 'command', 'description' => 'The web app tests pass', 'command' => 'bun test', 'directory' => 'apps/web']),
            TaskDeliverable::fromArray(['id' => 'error-copy', 'type' => 'review', 'description' => 'Error messages name the failing subtask']),
        ],
        'approvals' => [
            ['title' => 'Decide the packet', 'summary' => 'ADR 0169 records the caps.'],
        ],
        'diffFiles' => [
            ['path' => 'apps/gateway/app/Domain/Tasks/TaskReviewPacket.php', 'insertions' => 10, 'deletions' => 0],
            ['path' => 'notes/untracked.txt', 'insertions' => 4, 'deletions' => 1],
        ],
        'diff' => "diff --git a/packet.php b/packet.php\n+packet",
        'taskCheck' => 'composer check',
        'handoffStatus' => 'passed',
        'handoffExitCode' => 0,
        'evidence' => handoff_evidence(),
        'startCommit' => str_repeat('a', 40),
        'continued' => false,
        'opensPullRequest' => false,
    ];
    foreach ($overrides as $key => $value) {
        $values[$key] = $value;
    }

    return new TaskReviewPacket(
        groupBrief: $values['groupBrief'],
        subtaskId: $values['subtaskId'],
        subtaskTitle: $values['subtaskTitle'],
        subtaskBrief: $values['subtaskBrief'],
        deliverables: $values['deliverables'],
        approvals: $values['approvals'],
        diffFiles: $values['diffFiles'],
        diff: $values['diff'],
        taskCheck: $values['taskCheck'],
        handoffStatus: $values['handoffStatus'],
        handoffExitCode: $values['handoffExitCode'],
        evidence: $values['evidence'],
        startCommit: $values['startCommit'],
        continued: $values['continued'],
        opensPullRequest: $values['opensPullRequest'],
    )->render();
}

function handoff_evidence(string $baseMessage = 'Class "HomeScreen" not found'): TaskDeliverableEvidence
{
    $evidence = TaskDeliverableEvidence::fromArray([
        'diff' => [],
        'tests' => [
            'layout-repro' => [
                'exit_code' => 0,
                'cases' => [['name' => 'it works on the working tree', 'status' => 'passed']],
                'base_exit_code' => 2,
                'base_cases' => [
                    ['name' => 'it breaks the home screen layout', 'status' => 'failed', 'kind' => 'error', 'message' => $baseMessage],
                    ['name' => 'it still renders', 'status' => 'passed'],
                    ['name' => 'it counts the rows', 'status' => 'failed', 'kind' => 'failure', 'message' => 'Failed asserting that 1 is 2.'],
                ],
            ],
        ],
        'commands' => [
            'web-tests' => ['exit_code' => 0, 'output' => 'ok'],
        ],
    ]);
    expect($evidence)->not->toBeNull();

    return $evidence;
}

function packet_section(string $packet, string $heading): string
{
    $headings = [
        'Group brief',
        'Subtask brief',
        'Deliverables',
        'Earlier approved subtasks',
        'Diff stat',
        'Handoff',
        'Diff',
        'Retrieval',
    ];
    $starts = [];
    foreach ($headings as $name) {
        $marker = "\n\n".$name."\n";
        $position = strpos($packet, $marker);
        if ($position === false && str_starts_with($packet, $name."\n")) {
            $position = -2;
        }
        if ($position !== false) {
            $starts[$name] = $position + 2;
        }
    }
    if (! isset($starts[$heading])) {
        throw new RuntimeException('Missing section '.$heading);
    }
    $from = $starts[$heading] + strlen($heading) + 1;
    if ($heading === 'Retrieval') {
        $end = strpos($packet, "\n\n", $from);

        return $end === false ? substr($packet, $from) : substr($packet, $from, $end - $from);
    }
    $next = strlen($packet);
    foreach ($starts as $position) {
        if ($position > $starts[$heading] && $position < $next) {
            $next = $position;
        }
    }

    return rtrim(substr($packet, $from, $next - $from), "\n");
}
