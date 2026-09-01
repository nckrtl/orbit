<?php

declare(strict_types=1);

$root = dirname(__DIR__, 5);

$read = static fn (string $relative): string => (string) file_get_contents($root.'/'.$relative);

it('initializes one current feature-plan artifact', function () use ($read): void {
    $script = $read('bin/worktree-create');
    $ignore = $read('.gitignore');

    foreach ([
        'initialize_feature_plan',
        'mkdir -p "$worktree/.orbit"',
        '# Feature plan',
        'Review verdict: PENDING',
        '## Acceptance map',
        '## Implementation order',
        '## Review findings',
    ] as $needle) {
        expect($script)->toContain($needle);
    }

    expect($script)
        ->not->toContain('Reconciliation verdict')
        ->not->toContain('## Reconciliation notes');
    expect($ignore)->toContain('/.orbit/');
});

it('reads complete worktree listings without early-exit SIGPIPE', function () use ($read): void {
    $create = $read('bin/worktree-create');
    $remove = $read('bin/worktree-remove');

    foreach ([$create, $remove] as $script) {
        expect($script)
            ->not
            ->toContain("awk '/^worktree / { print substr(\$0, 10); exit }'")
            ->toContain("awk '/^worktree / && !found { print substr(\$0, 10); found=1 }'");
    }
});

it('keeps one builder and a bounded independent plan review', function () use ($read, $root): void {
    $planner = $read('.agents/skills/planning-features/SKILL.md');
    $reviewer = $read('.agents/skills/reviewing-feature-plans/SKILL.md');
    $developer = $read('.agents/skills/developing-features/SKILL.md');

    expect($planner)
        ->toContain('retained Builder')
        ->toContain('continue implementation after')
        ->toContain('Review verdict: PENDING')
        ->toContain('one row per issue criterion')
        ->toContain('Do not create slice files');

    expect($reviewer)
        ->toContain('reports plan quality only')
        ->toContain('`PASS`')
        ->toContain('`FIX`')
        ->toContain('`BLOCK`')
        ->toContain('Collect every known blocking finding')
        ->toContain('same reviewer')
        ->toContain('one correction')
        ->toContain('second non-`PASS`')
        ->toContain('stop automatic review cycling')
        ->toContain('Never approve a plan you authored');

    expect($developer)
        ->toContain('same retained Builder')
        ->toContain('independent plan `PASS`')
        ->toContain('One writer per issue');

    expect(file_exists($root.'/.agents/skills/reconciling-feature-blocks/SKILL.md'))->toBeFalse();
});

it('keeps implementation guidance on Orbit code and proof', function () use ($read): void {
    $skill = $read('.agents/skills/developing-features/SKILL.md');

    foreach ([
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

it('keeps pull-request review independent and closeout deterministic', function () use ($read): void {
    $review = $read('.agents/skills/reviewing-pull-requests/SKILL.md');
    $merge = $read('.agents/skills/merging-pull-requests/SKILL.md');

    expect($review)
        ->toContain('fresh reviewer')
        ->toContain('current pushed head')
        ->toContain('bin/e2e-topology prove <ISSUE>')
        ->toContain('bin/e2e-live <sha>')
        ->toContain('exactly `Approved.`')
        ->toContain('Collect every blocking')
        ->toContain('finding in one pass')
        ->toContain('Do not merge, promote, release a proved topology');

    expect($merge)
        ->toContain('deterministic closeout')
        ->toContain('gh pr merge <n> --merge')
        ->toContain('bin/e2e-standby promote <ISSUE>')
        ->toContain('bin/e2e-standby refresh')
        ->toContain('bin/worktree-remove <ISSUE> <slug>')
        ->toContain('verify GitHub, `origin/main`')
        ->toContain('standby identity, and cleanup state directly');
});

it('keeps issue creation current, atomic, and proof feasible', function () use ($read): void {
    $skill = $read('.agents/skills/creating-issues/SKILL.md');

    expect($skill)
        ->not->toContain('Status: Todo')
        ->not->toContain('Status: Backlog')->toContain('Set the Linear status field directly')->toContain(
            'Remove `Readiness` before moving the issue to `Todo`',
        )->toContain('Each acceptance criterion must have one available')->toContain('proof action')->toContain(
            'split them into ordered issues',
        )->toContain('component names are repository-owned')->toContain('`apps/e2e`')->toContain(
            'dependency graph is explicit and acyclic',
        )->toContain('compatibility bridge');
});

it('keeps repository guidance on standalone tasks and product boundaries', function () use ($read): void {
    $agents = $read('AGENTS.md');
    $readme = $read('README.md');
    $decisions = $read('docs/decisions/README.md');
    $topologies = $read('docs/reference/incus-topologies.md');

    expect($agents)
        ->toContain('## Independent agent-role skills')
        ->not
        ->toContain('reconciling-feature-blocks')
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
