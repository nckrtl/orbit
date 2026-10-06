<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskTurnInstructions;
use Illuminate\Support\Facades\Route;

describe('DeliverableProbe turn instructions and reference', function (): void {
    it('offers a privileged dry-run before ready_for_review without replacing handoff', function (?string $check): void {
        $instructions = TaskTurnInstructions::implementer([], $check, 1275);

        expect($instructions)
            ->toContain('Before ready_for_review, you can dry-run a privileged declared command deliverable via tasks-deliverable-probe')
            ->toContain('read its receipt with tasks-check-show')
            ->toContain('Run the declared deliverable exactly as stored')
            ->toContain('do not supply extra arguments or a free-form filter')
            ->toContain('Request probes sequentially, at most three per completion attempt')
            ->toContain('Probes do not count as handoff and do not replace the handoff check')
            ->toContain('--thread=1275 --outcome=ready_for_review');
    })->with([null, 'composer check']);

    it('documents TaskCheckKind probe and the shipped tasks:check:show operation names', function (): void {
        $reference = file_get_contents(base_path('../../docs/reference/tasks.md'));
        $probe = Route::getRoutes()->getByName('tasks:deliverable:probe');
        $show = Route::getRoutes()->getByName('tasks:check:show');

        expect(TaskCheckKind::Probe->value)->toBe('probe')
            ->and($probe)->not->toBeNull()
            ->and($show)->not->toBeNull()
            ->and($reference)
            ->toContain('### Deliverable probe')
            ->toContain('`kind` `'.TaskCheckKind::Probe->value.'`')
            ->toContain('`tasks-deliverable-probe` and `tasks-check-show`')
            ->toContain('`orbit tasks:deliverable:probe <group> <task> <deliverable>`')
            ->toContain('`orbit tasks:check:show <group> <task> <check>`')
            ->toContain('`POST /'.$probe->uri().'`')
            ->toContain('`GET /'.$show->uri().'`');
    });

    it('documents the declared payload, managed-user receipt, limits and non-gating result', function (): void {
        $reference = file_get_contents(base_path('../../docs/reference/tasks.md'));

        expect($reference)
            ->toContain('stored command and directory verbatim, with no extra arguments or free-form filter')
            ->toContain('empty Project command, no setup steps, and only that deliverable')
            ->toContain('`/tmp/orbit-check-<uid>-<random>`')
            ->toContain('at most three probes per completion attempt')
            ->toContain('while any baseline, handoff, or probe check runs in the group')
            ->toContain('A probe never gates handoff or counts as a handoff attempt')
            ->toContain('Probes do not count as handoff');

        foreach (['check_id', 'deliverable', 'command', 'managed_user', 'uid', 'tmpdir', 'head', 'tree', 'exit_code', 'base_exit_code', 'output_tail', 'started_at', 'finished_at'] as $field) {
            expect($reference)->toContain('`'.$field.'`');
        }
    });
});
