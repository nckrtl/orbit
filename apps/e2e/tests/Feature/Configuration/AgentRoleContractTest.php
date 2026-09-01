<?php

declare(strict_types=1);

$root = dirname(__DIR__, 5);

$read = static fn (string $relative): string => (string) file_get_contents($root.'/'.$relative);

it('initializes a standalone feature plan with each worktree', function () use ($read): void {
    $script = $read('bin/worktree-create');
    $ignore = $read('.gitignore');

    foreach ([
        'initialize_feature_plan',
        'mkdir -p "$worktree/.orbit"',
        '# Feature plan',
        'Review verdict: PENDING',
        'Reconciliation verdict: PENDING',
        '## Acceptance map',
        '## Implementation order',
        '## Review findings',
        '## Reconciliation notes',
    ] as $needle) {
        expect($script)->toContain($needle);
    }

    expect($ignore)->toContain('/.orbit/');
});

it('keeps planning, review, and reconciliation independently invokable', function () use ($read): void {
    $planner = $read('.agents/skills/planning-features/SKILL.md');
    $reviewer = $read('.agents/skills/reviewing-feature-plans/SKILL.md');
    $reconciler = $read('.agents/skills/reconciling-feature-blocks/SKILL.md');

    expect($planner)
        ->toContain('independently invokable planning role')
        ->toContain('Review verdict: PENDING')
        ->toContain('Reconciliation verdict: PENDING')
        ->toContain('one row per issue criterion')
        ->toContain('Do not create slice files');

    expect($reviewer)
        ->toContain('reports the quality of the plan only')
        ->toContain('`PASS`')
        ->toContain('`FIX`')
        ->toContain('`BLOCK`')
        ->toContain('Collect every known blocking finding')
        ->toContain('**Recommended resolution**')
        ->toContain('Never approve a plan you authored');

    expect($reconciler)
        ->toContain('`TECHNICAL_RESOLUTION`')
        ->toContain('`HUMAN_DECISION_REQUIRED`')
        ->toContain('smallest safe,')
        ->toContain('contract-preserving resolution')
        ->toContain('**Behavior changed:**')
        ->toContain('**Behavior unchanged:**')
        ->toContain('does not itself authorize any')
        ->toContain('external mutation')
        ->toContain('Do not edit product code, tests, proof files, Git history, Linear, or GitHub.');
});

it('keeps implementation guidance on Orbit code and proof', function () use ($read): void {
    $skill = $read('.agents/skills/developing-features/SKILL.md');

    foreach ([
        'may be invoked directly',
        'bin/e2e-topology acquire <ISSUE> <worktree>',
        'bin/e2e-topology shell <ISSUE> <role>',
        'proofs/<ISSUE>.json',
        'bin/e2e-topology prove <ISSUE>',
        'bin/e2e-live <full sha>',
        'Product feature branches never touch `apps/e2e` or `bin/e2e-*`.',
        'One issue per worktree and topology',
    ] as $needle) {
        expect($skill)->toContain($needle);
    }
});

it('keeps pull request roles on candidate verification and repository cleanup', function () use ($read): void {
    $review = $read('.agents/skills/reviewing-pull-requests/SKILL.md');
    $merge = $read('.agents/skills/merging-pull-requests/SKILL.md');

    expect($review)
        ->toContain('bin/e2e-topology prove <ISSUE>')
        ->toContain('bin/e2e-live <sha>')
        ->toContain('exactly `Approved.`')
        ->toContain('Collect every blocking')
        ->toContain('finding in one pass')
        ->toContain('Do not merge, promote, release a proved topology');

    expect($merge)
        ->toContain('gh pr merge <n> --merge')
        ->toContain('bin/e2e-standby promote <ISSUE>')
        ->toContain('bin/e2e-standby refresh')
        ->toContain('bin/worktree-remove <ISSUE> <slug>')
        ->toContain('verify GitHub, `origin/main`')
        ->toContain('standby identity, and cleanup state directly');
});

it('keeps issue creation focused on contract quality and feasibility', function () use ($read): void {
    $skill = $read('.agents/skills/creating-issues/SKILL.md');

    foreach ([
        'Status: Todo',
        'Readiness:',
        'Proof: incus',
        'Each acceptance criterion must have one available',
        'proof action',
        'Use `Backlog` when the request is rough or incomplete',
        'Use `Todo` when the contract is complete',
        'changes architecture',
        '`Proposed`',
        'exact-text approval',
        'refine the complete set against current',
        '`main` before finalizing',
        'dependency graph is explicit and acyclic',
        'compatibility bridge',
    ] as $needle) {
        expect($skill)->toContain($needle);
    }
});

it('keeps repository guidance on product and technical boundaries', function () use ($read): void {
    $agents = $read('AGENTS.md');
    $readme = $read('README.md');
    $decisions = $read('docs/decisions/README.md');
    $topologies = $read('docs/reference/incus-topologies.md');

    expect($agents)
        ->toContain('## Independent agent-role skills')
        ->toContain('Product feature branches never modify the harness')
        ->toContain('A proved topology is immutable evidence')
        ->toContain('Production release is separate from development proof');

    expect($readme)
        ->toContain('bin/worktree-create NCK-123 concise-feature-name')
        ->toContain('independently invokable')
        ->toContain('optional task guides')
        ->toContain('contributors and coding');

    expect($decisions)
        ->toContain('significant product or')
        ->toContain('technical direction')
        ->toContain('ADR 0010: Record decisions before implementation issues');

    expect($topologies)
        ->toContain('ADR 0005')
        ->toContain('ADR 0006')
        ->toContain('fresh proof, immutable proved attempts');
});
