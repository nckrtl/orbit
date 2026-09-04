<?php

declare(strict_types=1);

$root = dirname(__DIR__, 5);

$read = static fn (string $relative): string => (string) file_get_contents($root.'/'.$relative);

it('initializes one current feature-plan artifact', function () use ($read): void {
    $script = $read('bin/worktree-create');
    $ignore = $read('.gitignore');

    foreach ([
        'initialize_feature_plan',
        'mkdir -p "$worktree/.loop/proof"',
        '# Feature plan',
        'Review verdict: PENDING',
        '## Acceptance map',
        '## Implementation order',
        '## Deviations',
        '## Review findings',
    ] as $needle) {
        expect($script)->toContain($needle);
    }

    expect($script)
        ->toContain('$worktree/.loop/plan.md')
        ->not->toContain('$worktree/.or'.'bit/plan.md')
        ->not->toContain('Reconciliation verdict')
        ->not->toContain('## Reconciliation notes');
    expect($ignore)
        ->toContain('/.e2e/')
        ->not->toContain('/.orbit/')
        ->not->toContain('/.loop/');
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
        ->toContain('create the same workspace by hand')
        ->toContain('Review verdict: PENDING')
        ->toContain('one row per `Acceptance` item')
        ->toContain('every attached ADR `Decision` bullet the change touches')
        ->toContain('## Write the documentation')
        ->toContain('run `auditing-documentation` in its default issue scope')
        ->toContain('following `writing-documentation`')
        ->toContain('the reference is the issue and its ADRs, not the code')
        ->toContain('neither is a code boundary or a component')
        ->toContain('or to the page from the Documentation section')
        ->toContain('needs no label and is bounded by `Scope`')
        ->toContain('whose message starts with `docs:`')
        ->toContain('other than exactly `Todo` or `In Progress`')
        ->toContain('does not follow the `creating-issues` template')
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
        ->toContain('rather than against code that does not exist yet')
        ->toContain('where a component is one of the five Composer projects')
        ->toContain('`bin/e2e-*` counts as `apps/e2e`')
        ->toContain('returns to `Backlog` with a `Readiness` section through `creating-issues`')
        ->not->toContain('same reviewer')
        ->not->toContain('one correction')
        ->not->toContain('second non-`PASS`');

    expect($developer)
        ->toContain('may be invoked directly')
        ->toContain('other than exactly `Todo` or `In Progress`')
        ->toContain('One issue per worktree')
        ->toContain('Discovery and proof use separate topologies')
        ->toContain('lists every `Acceptance` item in the issue\'s order')
        ->toContain('When no plan exists, run `auditing-documentation`')
        ->toContain('carry the audit\'s `Fixed` and `Reported` lists into the pull request body')
        ->toContain('recorded under `## Deviations` in the plan and in the pull request body')
        ->toContain('The proposed body opens with `Issue: <ID>`')
        ->toContain('every reported finding from either audit with its owner')
        ->toContain('Include current `origin/main`')
        ->not->toContain('retained Builder')
        ->not->toContain('plan `PASS`');

    expect(file_exists($root.'/.agents/skills/reconciling-feature-blocks/SKILL.md'))->toBeFalse();
});

it('keeps implementation guidance on Orbit code and proof', function () use ($read): void {
    $skill = $read('.agents/skills/developing-features/SKILL.md');

    foreach ([
        'bin/e2e-topology acquire <ISSUE> <worktree>',
        'bin/e2e-topology shell <ISSUE> <role>',
        '.loop/proof/<ISSUE>.json',
        'bin/e2e-topology prove <ISSUE>',
        'shell --proof',
        'action must exit `0`',
        'Product feature branches never touch harness code as defined above.',
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
        ->not->toContain('bin/e2e-live')->toContain('bin/e2e-topology status <ISSUE>')->toContain(
            'the drift fixes and deviations the PR body lists',
        )->toContain('exactly `Approved.`')->toContain(
            'Collect every blocking',
        )->toContain('finding in one pass')->toContain('Do not merge, promote, release a proved topology')
        ->not->toContain('fresh reviewer');

    expect($merge)
        ->toContain('Close out one independently approved pull request')
        ->toContain('external orchestrator merges it')
        ->toContain('Do not run a merge command')
        ->toContain('bin/e2e-topology-snapshot promote <ISSUE>')
        ->toContain('Do not substitute a refresh')
        ->toContain('For every candidate, if `main` moved after approval')
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

it('accepts Todo or In Progress for development', function () use ($read): void {
    $skill = $read('.agents/skills/developing-features/SKILL.md');
    $manifest = $read('.agents/skills/developing-features/agents/openai.yaml');

    expect($skill)
        ->toContain('other than exactly `Todo` or `In Progress`')
        ->not->toContain('issue is not `Todo`');
    expect($manifest)
        ->toContain('eligible Todo or In Progress Orbit issue')
        ->not->toContain('supplied Todo Orbit issue');
});

it('keeps one external-orchestrator lifecycle', function () use ($read): void {
    $developer = $read('.agents/skills/developing-features/SKILL.md');
    $reviewer = $read('.agents/skills/reviewing-pull-requests/SKILL.md');
    $merger = $read('.agents/skills/merging-pull-requests/SKILL.md');
    $developerManifest = $read('.agents/skills/developing-features/agents/openai.yaml');
    $reviewerManifest = $read('.agents/skills/reviewing-pull-requests/agents/openai.yaml');
    $mergerManifest = $read('.agents/skills/merging-pull-requests/agents/openai.yaml');

    expect($developer)
        ->toContain('external orchestrator owns pull-request creation')
        ->toContain('must not invoke `gh`')
        ->toContain('push the branch')
        ->toContain('pushed head SHA, branch and base')
        ->toContain('complete proposed body')
        ->toContain('retained-proof binding');
    expect($reviewer)
        ->toContain('external orchestrator owns review publication')
        ->toContain('must not invoke `gh`')
        ->toContain('Return the formal review')
        ->toContain('complete findings')
        ->toContain('exact reviewed SHA');
    expect($merger)
        ->toContain('after the external orchestrator merges it')
        ->toContain('authoritative read-only GitHub state')
        ->toContain('Do not run a merge command')
        ->toContain('only mutations are proof promotion and resource cleanup');

    expect($developerManifest)
        ->toContain('Todo or In Progress')
        ->toContain('complete pull-request body and evidence')
        ->toContain('without mutating GitHub');
    expect($reviewerManifest)
        ->toContain('complete formal-review payload')
        ->toContain('without mutating GitHub');
    expect($mergerManifest)
        ->toContain('external orchestrator merges')
        ->toContain('exact second-approved removal head')
        ->toContain('promote its proof, and clean up');

    foreach ([$developer, $reviewer, $merger, $developerManifest, $reviewerManifest, $mergerManifest] as $contract) {
        expect($contract)
            ->not->toContain('`publish`')
            ->not->toContain('`handoff`')
            ->not->toContain('`merge-and-closeout`')
            ->not->toContain('`closeout-only`')
            ->not->toContain('Anna')
            ->not->toContain('Tom')
            ->not->toContain('Herdr')
            ->not->toContain('retained Builder');
    }
});

it('binds the external merge closeout lifecycle', function () use ($read): void {
    $developer = $read('.agents/skills/developing-features/SKILL.md');
    $planReviewer = $read('.agents/skills/reviewing-feature-plans/SKILL.md');
    $reviewer = $read('.agents/skills/reviewing-pull-requests/SKILL.md');
    $merger = $read('.agents/skills/merging-pull-requests/SKILL.md');

    expect($developer)
        ->toContain('complete `.loop/` workspace')
        ->toContain('pushes one commit that deletes `.loop/` and changes nothing else')
        ->toContain('evaluates retained-proof equivalence')
        ->toContain('returns the removal head for a fresh second approval');
    expect($reviewer)
        ->toContain('sole parent to be the approved workspace head')
        ->toContain('only deletions below `.loop/`')
        ->toContain('retained proof to be `exact` or `equivalent`')
        ->toContain('fresh review bound to the exact removal-head SHA');
    expect($merger)
        ->toContain('exact independently approved head')
        ->toContain('still carries `.loop/`')
        ->toContain("Require the entire parent-to-head difference to be\n   deletions below `.loop/`")
        ->toContain('second independent `Approved.` review bound to the removal head')
        ->toContain('Verify the external merge')
        ->toContain('exact second parent')
        ->toContain('tree to equal the accepted head')
        ->toContain('absence of `.loop/` from the merged tree')
        ->toContain('bin/e2e-topology-snapshot promote <ISSUE>')
        ->toContain('bin/worktree-remove <ISSUE> <slug>');
    expect($planReviewer)
        ->toContain('Commit the reviewed `.loop/plan.md` and no other change with a message beginning `plan:`');
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
        )->toContain('composer issue:lint')->toContain(
            'return a conflicting or incomplete issue to `Backlog` and write the `Readiness` section',
        )->toContain('only the last is an action in `.loop/proof/<ISSUE>.json`');

    expect($skill)->toContain('which are not harness code');

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
        ->toContain('Accepted records are immutable')
        ->toContain('commit `docs/generated/context.json` with the record');

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
        ->toContain('## Reference')
        ->toContain('a non-zero exit means the index matched nothing')
        ->toContain('The command is never run without a filter')
        ->toContain('names its owner')
        ->toContain('creates the issue from the merged pull request body')
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
    expect($agents)->toContain('Product feature branches never modify the harness')->toContain('are not harness code');
    expect($agents)->toContain('A proved topology is immutable evidence');
    expect($agents)->toContain('Production release is separate from development proof');

    expect($readme)
        ->toContain('bin/worktree-create ')
        ->toContain(' concise-feature-name')
        ->toContain('independently invokable')
        ->toContain('optional task guides')
        ->toContain('contributors and coding')
        ->not->toContain('reconciliation');

    expect($developerManifest)
        ->toContain('Todo or In Progress Orbit issue')
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
