<?php

declare(strict_types=1);

$root = dirname(__DIR__, 5);

$read = static fn (string $relative): string => (string) file_get_contents($root.'/'.$relative);

it('keeps the developing skill on the nine-step flow', function () use ($read): void {
    $skill = $read('.agents/skills/developing-orbit-features/SKILL.md');

    foreach ([
        'bin/e2e-topology acquire <ISSUE> <worktree>',
        'bin/e2e-topology shell <ISSUE> <role>',
        'proofs/<ISSUE>.json',
        'bin/e2e-topology release <ISSUE>',
        'bin/e2e-topology prove <ISSUE>',
        'git merge main',
        'Feature branches never touch `apps/e2e` or `bin/e2e-*`.',
        '## Harness issues',
        'bin/e2e-live <full sha>',
        'follow **Harness issues** below',
        '"Harness: `bin/e2e-live <sha>` passed."',
        '`apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`',
    ] as $needle) {
        expect($skill)->toContain($needle);
    }

    foreach (['yaml', 'handoff', 'gpt-', 'fork_turns', 'post_deployment'] as $absent) {
        expect(strtolower($skill))->not->toContain($absent);
    }
});

it('keeps the reviewing skill on re-proving', function () use ($read): void {
    $skill = $read('.agents/skills/reviewing-orbit-pull-requests/SKILL.md');

    foreach ([
        'git merge main',
        'bin/e2e-topology prove <ISSUE>',
        'bin/e2e-live <sha>',
        'exactly `Approved.`',
        'topology alive for the merge agent',
    ] as $needle) {
        expect($skill)->toContain($needle);
    }

    foreach (['yaml', 'gates:'] as $absent) {
        expect(strtolower($skill))->not->toContain($absent);
    }
});

it('keeps the merging skill on promote then clean up', function () use ($read): void {
    $skill = $read('.agents/skills/merging-orbit-pull-requests/SKILL.md');

    foreach ([
        'gh pr merge <n> --merge',
        'bin/e2e-standby promote <ISSUE>',
        'bin/e2e-standby refresh',
        'bin/worktree-remove <ISSUE> <slug>',
        'One PR at a time',
    ] as $needle) {
        expect($skill)->toContain($needle);
    }

    expect(strtolower($skill))->not->toContain('yaml');
});

it('keeps the pull request template short', function () use ($read): void {
    $template = $read('.github/pull_request_template.md');

    expect($template)
        ->toContain('## What')
        ->and($template)
        ->toContain('## Proof')
        ->and($template)
        ->toContain('proofs/NCK-123.json')
        ->and(substr_count($template, "\n"))
        ->toBeLessThan(20);
});

it('keeps the workflow reference aligned with the skills', function () use ($read): void {
    $reference = $read('docs/reference/development-workflow.md');

    foreach ([
        '## Feature flow',
        '## Harness flow',
        'bin/e2e-topology shell NCK-123 <role>',
        'bin/e2e-standby promote NCK-123',
        'bin/e2e-live <sha>',
        '`<worktree>/.e2e/`',
        'Feature branches never modify the harness.',
        '`apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`',
    ] as $needle) {
        expect($reference)->toContain($needle);
    }

    expect($reference)->not->toContain('14-step');
});
