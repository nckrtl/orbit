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
    foreach ([$read('bin/worktree-create'), $read('bin/worktree-remove')] as $script) {
        expect($script)->not->toContain("awk '/^worktree / { print substr(\$0, 10); exit }'");
        expect($script)->toContain("awk '/^worktree / && !found { print substr(\$0, 10); found=1 }'");
        expect($script)->not->toContain('grep -Fxq "worktree $worktree"');
        expect($script)->toContain('grep -Fx "worktree $worktree" >/dev/null');
    }
});

it('keeps planning, plan review, and development independently invokable', function () use ($read, $root): void {
    $planner = $read('.agents/skills/planning-features/SKILL.md');
    $reviewer = $read('.agents/skills/reviewing-feature-plans/SKILL.md');
    $developer = $read('.agents/skills/developing-features/SKILL.md');

    expect($planner)
        ->toContain('independently invokable planning task')
        ->toContain('structure manually')
        ->toContain('Review verdict: PENDING')
        ->toContain('one row per issue criterion')
        ->toContain('Do not create slice files')
        ->not->toContain('retained Builder')
        ->not->toContain('second non-`PASS`');

    expect($reviewer)
        ->toContain('reports plan quality only')
        ->toContain('may update only `Review verdict` and `## Review findings`')
        ->toContain('never edits planning content')
        ->toContain('`PASS`')
        ->toContain('`FIX`')
        ->toContain('`BLOCK`')
        ->toContain('Collect every known blocking finding')
        ->toContain('smallest safe recommended')
        ->toContain('Never approve a')
        ->not->toContain('same reviewer')
        ->not->toContain('one correction')
        ->not->toContain('second non-`PASS`');

    expect($developer)
        ->toContain('may be invoked directly')
        ->toContain('One issue per worktree and topology')
        ->not->toContain('retained Builder')
        ->not->toContain('plan `PASS`');

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

it('binds review and merge to one exact remote head', function () use ($read): void {
    $review = $read('.agents/skills/reviewing-pull-requests/SKILL.md');
    $merge = $read('.agents/skills/merging-pull-requests/SKILL.md');

    expect($review)
        ->toContain('exact remote PR head')
        ->toContain('must not merge or rebase `main`')
        ->toContain('stop until the candidate is updated and pushed')
        ->toContain('bin/e2e-topology prove <ISSUE>')
        ->toContain('bin/e2e-live <sha>')
        ->toContain('exactly `Approved.`')
        ->toContain('Collect every blocking')
        ->toContain('finding in one pass')
        ->toContain('Do not merge, promote, release a proved topology')
        ->not->toContain('fresh reviewer');

    expect($merge)
        ->toContain('deterministic closeout')
        ->toContain('gh pr merge <n> --merge --match-head-commit <sha>')
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

it('keeps repository guidance and agent manifests current', function () use ($read): void {
    $agents = $read('AGENTS.md');
    $readme = $read('README.md');
    $developerManifest = $read('.agents/skills/developing-features/agents/openai.yaml');
    $decisions = $read('docs/decisions/README.md');
    $topologies = $read('docs/reference/incus-topologies.md');

    expect($agents)->toContain('## Independent agent-role skills');
    expect($agents)->not->toContain('reconciling-feature-blocks');
    expect($agents)->not->toContain('Builder');
    expect($agents)->toContain('Product feature branches never modify the harness');
    expect($agents)->toContain('A proved topology is immutable evidence');
    expect($agents)->toContain('Production release is separate from development proof');

    expect($readme)
        ->toContain('bin/worktree-create NCK-123 concise-feature-name')
        ->toContain('independently invokable')
        ->toContain('optional task guides')
        ->toContain('contributors and coding')
        ->not->toContain('reconciliation');

    expect($developerManifest)
        ->toContain('Todo Orbit issue')
        ->not->toContain('Ready Orbit issue');

    expect($decisions)
        ->toContain('significant product or')
        ->toContain('technical direction')
        ->toContain('ADR 0010: Record decisions before implementation issues');

    expect($topologies)
        ->toContain('ADR 0005')
        ->toContain('ADR 0006')
        ->toContain('fresh proof, immutable proved attempts');
});
