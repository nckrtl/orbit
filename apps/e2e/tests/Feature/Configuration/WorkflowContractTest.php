<?php

declare(strict_types=1);

$root = dirname(__DIR__, 5);

$read = static fn (string $relative): string => (string) file_get_contents($root.'/'.$relative);

it('initializes one gitignored preflight plan with each worktree', function () use ($read): void {
    $script = $read('bin/worktree-create');
    $ignore = $read('.gitignore');

    foreach ([
        'initialize_preflight',
        'mkdir -p "$worktree/.orbit"',
        '# Feature preflight',
        'Verdict: PENDING',
        'Review round: 0',
        '## Acceptance map',
        '## Implementation order',
        '## Reviewer findings',
    ] as $needle) {
        expect($script)->toContain($needle);
    }

    expect($ignore)->toContain('/.orbit/');
});

it('keeps feature preflight lightweight and independently reviewed', function () use ($read): void {
    $planner = $read('.agents/skills/planning-features/SKILL.md');
    $reviewer = $read('.agents/skills/reviewing-feature-plans/SKILL.md');

    foreach ([
        '.orbit/plan.md',
        'Leave `Verdict: PENDING`',
        'Do not create separate slice files',
        'A new requirement belongs in a separate Linear issue.',
    ] as $needle) {
        expect($planner)->toContain($needle);
    }

    foreach ([
        '`PASS`',
        '`FIX`',
        '`BLOCK`',
        'Collect every blocking finding',
        '**Recommended resolution**',
        'smallest safe contract, scope, dependency, or harness change',
        'fresh correction',
        'Repeat with fresh agents until',
        'Never approve a plan you authored.',
        'Linear issue, which has status `In Progress`',
    ] as $needle) {
        expect($reviewer)->toContain($needle);
    }
    expect($reviewer)->not->toContain('unchanged Ready issue');
});

it('requires a passed preflight before one implementer starts', function () use ($read): void {
    $skill = $read('.agents/skills/developing-features/SKILL.md');

    foreach ([
        '.orbit/plan.md` with `Verdict: PASS`',
        'single implementer',
        'approved implementation order',
        'bin/e2e-topology acquire <ISSUE> <worktree>',
        'bin/e2e-topology shell <ISSUE> <role>',
        'proofs/<ISSUE>.json',
        'bin/e2e-topology release <ISSUE>',
        'bin/e2e-topology prove <ISSUE>',
        'git merge main',
        'Feature branches never touch `apps/e2e` or `bin/e2e-*`.',
        '## Harness issues',
        '## Delegation',
        'Do not assign one agent per plan increment',
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

it('keeps pull request review bounded and on re-proving', function () use ($read): void {
    $skill = $read('.agents/skills/reviewing-pull-requests/SKILL.md');

    foreach ([
        'git merge main',
        'bin/e2e-topology prove <ISSUE>',
        'bin/e2e-live <sha>',
        'exactly `Approved.`',
        'topology alive for the merge agent',
        'Collect all blocking findings',
        'A genuinely new requirement becomes separate Linear work',
        'Do not drip known findings across rounds.',
    ] as $needle) {
        expect($skill)->toContain($needle);
    }

    foreach (['yaml', 'gates:'] as $absent) {
        expect(strtolower($skill))->not->toContain($absent);
    }
});

it('keeps the merging skill on promote then clean up', function () use ($read): void {
    $skill = $read('.agents/skills/merging-pull-requests/SKILL.md');

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
        ->toContain('proofs/<ISSUE>.json')
        ->and(substr_count($template, "\n"))
        ->toBeLessThan(20);
});

it('keeps the workflow reference aligned with the skills', function () use ($read): void {
    $reference = $read('docs/reference/development-workflow.md');

    foreach ([
        '## Feature flow',
        '## Correction loop',
        '## Harness flow',
        'A Linear issue with status `Todo` is refined and queued',
        'Claim, worktree, and preflight',
        'Every `FIX` starts a fresh correction',
        'Repeat until `PASS` or',
        'same implementer',
        'bin/e2e-topology shell <ISSUE> <role>',
        'bin/e2e-standby promote <ISSUE>',
        'bin/e2e-live <sha>',
        '`<worktree>/.orbit/plan.md`',
        '`<worktree>/.e2e/`',
        'Feature branches never modify the harness.',
        '`apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`',
        '0007-nine-step-feature-flow.md',
        '0011-linear-lifecycle-states.md',
    ] as $needle) {
        expect($reference)->toContain($needle);
    }

    foreach (['per-slice', 'generated archive', 'review-import', 'ready-equivalent', 'nck'] as $absent) {
        expect(strtolower($reference))->not->toContain($absent);
    }
});

it('keeps the root guidance and the issue skill on the tightened flow', function () use ($read): void {
    $agents = $read('AGENTS.md');
    $issues = $read('.agents/skills/creating-issues/SKILL.md');

    foreach ([
        'docs/reference/development-workflow.md',
        '.agents/skills/planning-features',
        '.agents/skills/reviewing-feature-plans',
        '.agents/skills/developing-features',
        'moves it to `In Progress`',
        'Verdict: PASS',
        'One implementer owns the complete feature',
        'Feature branches never modify the harness',
        'proofs/<ISSUE>.json',
    ] as $needle) {
        expect($agents)->toContain($needle);
    }

    foreach ([
        'Proof: incus',
        'issue never lists it',
        'Status: Todo',
        'Implementation readiness',
        'A Linear issue with status `Todo` is refined and ready to enter the execution',
    ] as $needle) {
        expect($issues)->toContain($needle);
    }

    foreach ([$agents, $issues] as $document) {
        foreach ([
            '14-step',
            'Compound',
            'post_deployment',
            'Composition',
            'project manager',
            'Ready-equivalent',
            'NCK',
        ] as $absent) {
            expect($document)->not->toContain($absent);
        }
    }
});

it('makes issue creation ADR-first, feasibility-checked, and parallel-safe', function () use ($read): void {
    $agents = $read('AGENTS.md');
    $issues = $read('.agents/skills/creating-issues/SKILL.md');
    $reference = $read('docs/reference/development-workflow.md');
    $decisions = $read('docs/decisions/README.md');

    foreach ([
        '`Backlog` means the issue is rough or incomplete',
        '`Todo` means the implementation contract is complete',
        '`In Progress` begins when Tom selects',
        'reserved for started work that cannot continue',
        'explicitly to `Backlog` or `Todo`',
        'current `main`',
        'product, migration, proof, and harness',
        'lifecycle, ownership, migration, compatibility, failure, rollback, and removal',
        'one available proof action',
        'explicit and acyclic',
        'real prerequisites',
        'compatibility bridge',
        'Independent roots',
        'dependents remain in `Todo`',
    ] as $needle) {
        expect($issues)->toContain($needle);
    }

    foreach ([
        '`PASS` is the normal result',
        '`FIX` means the issue remains implementable',
        'Linear comment',
        'issue-creation failure',
    ] as $needle) {
        expect($issues)->toContain($needle);
        expect($reference)->toContain($needle);
    }

    foreach ([$agents, $issues, $reference] as $document) {
        expect($document)
            ->toContain('origin/main')
            ->not->toContain('Preparation');
    }

    expect($issues)
        ->toContain('does not create `.orbit/plan.md`')
        ->not->toContain('Do not create a repository plan');
});

it('records approved ADRs before deriving implementation issues', function () use ($read): void {
    $issues = $read('.agents/skills/creating-issues/SKILL.md');
    $decisions = $read('docs/decisions/README.md');

    foreach ([
        'architectural significance',
        '`Proposed`',
        'exact final text',
        '`Accepted`',
        'Accepted ADRs remain immutable',
        'extends, amends, or supersedes',
        'commit contains only the approved ADR',
        'local `main` matches the current remote base',
        'unrelated work',
        'pull request remains optional',
        'before implementation issues are derived',
    ] as $needle) {
        expect($issues)->toContain($needle);
        expect($decisions)->toContain($needle);
    }
});

it('amends ADR 0007 without restoring the discarded machinery', function () use ($read): void {
    $adr = $read('docs/decisions/0007-nine-step-feature-flow.md');

    foreach ([
        'Amended on 2026-08-31',
        'one gitignored `.orbit/plan.md`',
        'Every `FIX` starts a fresh correction planner',
        'there is no nested feature orchestrator',
        'New requirements become separate work.',
    ] as $needle) {
        expect($adr)->toContain($needle);
    }

    foreach ([
        'per-increment state files',
        'mandatory per-increment commits',
        'review-import tooling',
        'generated run archives',
    ] as $needle) {
        expect($adr)->toContain($needle);
    }
});

it('separates refinement, active delivery, review, and parked blockers', function () use ($read): void {
    $adr = $read('docs/decisions/0011-linear-lifecycle-states.md');
    $reference = $read('docs/reference/development-workflow.md');
    $issues = $read('.agents/skills/creating-issues/SKILL.md');

    foreach ([
        '`Backlog`: a rough or incomplete issue',
        '`Todo`: a refined, proof-feasible issue',
        '`In Progress`: Tom has selected the issue',
        '`Blocked`: a started issue cannot continue',
        '`In Review`: implementation and required proof are complete',
        '`Done`: the PR is merged to `main`',
        '`Canceled`: the issue is canceled or superseded',
        'Execution concurrency is the number of Linear issues',
        '`Blocked` does not consume execution capacity',
        'first issue whose declared prerequisites are all `Done`',
        'moves the issue to `Blocked`',
        'Tom adds a Linear comment naming',
        'moves it to `In Progress` and verifies the transition',
        'synchronizes the worktree with current `origin/main`',
        'runs a fresh planner and fresh preflight review',
    ] as $needle) {
        expect($adr)->toContain($needle);
    }

    foreach ([$reference, $issues] as $document) {
        expect($document)
            ->toContain('without consuming execution');
    }

    expect($reference)->toContain('starts a wholly fresh')->and($issues)->toContain('wholly fresh');
});
