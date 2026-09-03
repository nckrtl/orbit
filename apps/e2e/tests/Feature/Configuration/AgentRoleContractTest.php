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

it('releases retained proof and discovery before removing a worktree', function () use ($read): void {
    $script = $read('bin/worktree-remove');

    expect($script)
        ->toContain('if [[ -f "$worktree/.e2e/proof-attempt.json" ]]')
        ->toContain('release "$linear_id" "--worktree=$worktree" --proof')
        ->toContain('if [[ -f "$worktree/.e2e/attempt.json" ]]')
        ->toContain('release "$linear_id" "--worktree=$worktree"');
});

it('keeps planning, plan review, and development independently invokable', function () use ($read, $root): void {
    $planner = $read('.agents/skills/planning-features/SKILL.md');
    $reviewer = $read('.agents/skills/reviewing-feature-plans/SKILL.md');
    $developer = $read('.agents/skills/developing-features/SKILL.md');

    expect($planner)
        ->toContain('independently invokable planning task')
        ->toContain('create the same headings by hand')
        ->toContain('Review verdict: PENDING')
        ->toContain('one row per `Acceptance` item')
        ->toContain('every attached ADR `Decision` bullet the change touches')
        ->toContain('## Write the documentation')
        ->toContain('run `auditing-documentation` in its default issue scope')
        ->toContain('following `writing-documentation`')
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
        ->toContain('every boundary is inside a component the issue is labeled with')
        ->toContain('the diff under `docs/`')
        ->toContain('pass `composer docs-lint`')
        ->not->toContain('same reviewer')
        ->not->toContain('one correction')
        ->not->toContain('second non-`PASS`');

    expect($developer)
        ->toContain('may be invoked directly')
        ->toContain('One issue per worktree')
        ->toContain('Discovery and proof use separate topologies')
        ->toContain('lists every `Acceptance` item in the issue\'s order')
        ->toContain('When no plan exists, run `auditing-documentation`')
        ->toContain('carry the audit\'s `Fixed` list into the pull request body')
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
        'shell --proof',
        'action must exit `0`',
        'Product feature branches never touch `apps/e2e` or `bin/e2e-*`.',
        'Discovery and proof use separate topologies',
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
        ->toContain('retained immutable proof')
        ->toContain('repository-owner-approved behavior')
        ->toContain('issue-specific proof')
        ->not->toContain('validation-clone lifecycle')
        ->not->toContain('bin/e2e-live')->toContain('exactly `Approved.`')->toContain(
            'Collect every blocking',
        )->toContain('finding in one pass')->toContain('Do not merge, promote, release a proved topology')
        ->not->toContain('fresh reviewer');

    expect($merge)
        ->toContain('deterministic closeout')
        ->toContain('gh pr merge <n> --merge --match-head-commit <sha>')
        ->toContain('bin/e2e-topology-snapshot promote <ISSUE>')
        ->toContain('Do not substitute a refresh')
        ->not
        ->toContain('bin/e2e-topology-snapshot refresh')
        ->toContain('bin/worktree-remove <ISSUE> <slug>')
        ->toContain('verify GitHub, `origin/main`')
        ->toContain('topology snapshot identity, and cleanup state directly');

    expect($read('.agents/skills/developing-features/SKILL.md'))
        ->toContain('repository-owner-approved behavior')
        ->toContain('issue-specific proof')
        ->not->toContain('bin/e2e-live');
});

it('keeps issue creation current, atomic, and proof feasible', function () use ($read): void {
    $skill = $read('.agents/skills/creating-issues/SKILL.md');

    expect($skill)
        ->not->toContain('Status: Todo')
        ->not->toContain('Status: Backlog')->toContain('never restates a Decision bullet from an ADR')->toContain(
            'Delete the section before `Todo`',
        )->toContain('one proof action that exists today')->toContain('Only a leaf issue is claimable')->toContain(
            'they are separate children',
        )->toContain('`apps/e2e`')->toContain('relation graph is explicit and acyclic')->toContain(
            'compatibility bridge',
        )->toContain('composer issue:lint');

    expect($read('.agents/skills/creating-issues/template.md'))
        ->toContain('## Scope')
        ->toContain('## Acceptance')
        ->toContain('Proof:');
});

it('keeps decision records templated and linted', function () use ($read, $root): void {
    $skill = $read('.agents/skills/recording-decisions/SKILL.md');
    $template = $read('.agents/skills/recording-decisions/template.md');

    expect($skill)
        ->toContain('composer docs-lint')
        ->toContain('orbit.adr_structure')
        ->toContain('orbit.adr_language')
        ->toContain('Accepted records are immutable');

    foreach ([
        '## Status',
        '## Context',
        '## Decision',
        '## Rejected alternatives',
        '## Consequences',
        '## Affects',
        '- Verify:',
    ] as $needle) {
        expect($template)->toContain($needle);
    }

    expect($read('AGENTS.md'))
        ->toContain('`recording-decisions`')
        ->toContain('`writing-documentation`')
        ->toContain('`auditing-documentation`');

    $writing = $read('.agents/skills/writing-documentation/SKILL.md');
    $auditing = $read('.agents/skills/auditing-documentation/SKILL.md');

    expect($writing)
        ->toContain('## Authority')
        ->toContain('| Kind | Lives in | Holds | Never holds |')
        ->toContain('never a product fact')
        ->toContain('composer docs-lint');

    expect($auditing)
        ->toContain('The default scope is one issue')
        ->toContain('composer docs-context')
        ->toContain('The whole corpus is the scope only when the caller asks for it')
        ->toContain('A finding outside the scope is recorded, never fixed in passing')
        ->toContain('## Documentation audit');
    expect(file_exists($root.'/docs/decisions/TEMPLATE.md'))->toBeFalse();
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
    expect($agents)->not->toContain('after plan approval');
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
