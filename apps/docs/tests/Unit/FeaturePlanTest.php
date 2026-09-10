<?php

declare(strict_types=1);

use App\Documentation\FeaturePlan;

it('accepts current plans with future tests, nested notes, no findings, and pipes in code', function (): void {
    $plan = file_get_contents(dirname(__DIR__).'/Fixtures/feature-plan.md');
    $template = file_get_contents(dirname(__DIR__, 4).'/.agents/skills/planning-features/template.md');

    expect(new FeaturePlan()->findings($plan, $template, 'ORB-999', 'discovery'))->toBe([]);
});

it('rejects unfinished or malformed plan structure', function (string $before, string $after, string $error): void {
    $plan = str_replace($before, $after, file_get_contents(dirname(__DIR__).'/Fixtures/feature-plan.md'));
    $template = file_get_contents(dirname(__DIR__, 4).'/.agents/skills/planning-features/template.md');

    expect(implode("\n", new FeaturePlan()->findings($plan, $template, 'ORB-999', 'discovery')))->toContain($error);
})->with([
    'old format' => ['Plan format: 1', 'Plan format: 0', 'Plan format header'],
    'wrong issue' => ['Issue: ORB-999', 'Issue: ORB-998', 'Issue header'],
    'wrong flow' => ['Flow: discovery', 'Flow: proof', 'Flow header'],
    'duplicate header' => ['Issue: ORB-999', "Issue: ORB-999\nIssue: ORB-998", 'Issue header'],
    'missing section' => ['## Outcome', '### Outcome', 'Required sections'],
    'duplicate section' => ['## Incus observations', '## Outcome', 'Required sections'],
    'empty section' => ['Show the selected delivery flow.', '', 'Fill Outcome'],
    'placeholder' => ['Show the selected delivery flow.', '{{OUTCOME}}', 'unfinished placeholder'],
    'bare list' => ['- Keep topology acquisition unchanged.', '-', 'unfinished placeholder'],
    'bare step' => ['1. Add the focused test, then implement the output.', '1.', 'numbered step'],
    'missing exclusion' => ['Out:', 'Outside:', 'In: and Out:'],
    'empty cell' => ['| Reports discovery | bin/loop-flow |', '| Reports discovery | |', 'Acceptance row 1'],
    'extra cell' => ['| Reports discovery | bin/loop-flow |', '| Reports discovery | bin/loop-flow | extra |', 'Acceptance row 1'],
    'interrupted table' => ['| Reports discovery |', "\n| Reports discovery |", 'uninterrupted table'],
    'missing leading pipe' => ['| Reports discovery |', 'Reports discovery |', 'uninterrupted table'],
    'placeholder cell' => ['| Reports discovery | bin/loop-flow |', '| Reports discovery | TBD |', 'Acceptance row 1'],
    'wrong header' => ['| Criterion | Boundary | Focused proof |', '| Item | Boundary | Focused proof |', 'template table header'],
    'fenced section' => ['## Outcome', "```md\n## Outcome\n```", 'Required sections'],
]);

it('rejects the new worktree scaffold until it is filled', function (): void {
    $template = file_get_contents(dirname(__DIR__, 4).'/.agents/skills/planning-features/template.md');
    $plan = str_replace(['{{ISSUE}}', '{{FLOW}}'], ['ORB-999', 'discovery'], $template);

    expect(implode("\n", new FeaturePlan()->findings($plan, $template, 'ORB-999', 'discovery')))->toContain('unfinished placeholder');
});
