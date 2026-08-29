<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('keeps the approved workflow contracts aligned', function (): void {
    $root = dirname(__DIR__, 5);
    $issue = File::get($root.'/.agents/skills/creating-orbit-issues/SKILL.md');
    $worker = File::get($root.'/.agents/skills/developing-orbit-features/SKILL.md');
    $review = File::get($root.'/.agents/skills/reviewing-orbit-pull-requests/SKILL.md');
    $merge = File::get($root.'/.agents/skills/merging-orbit-pull-requests/SKILL.md');
    $pr = File::get($root.'/.github/pull_request_template.md');
    $rootGuidance = File::get($root.'/AGENTS.md');
    $workflow = File::get($root.'/docs/reference/development-workflow.md');
    $topologies = File::get($root.'/docs/reference/incus-topologies.md');

    expect($issue)->toMatch('/Use `automated`.*Set all live fields to\s+`none`/s');
    expect($issue)->toMatch('/Use `live`.*`orbit node:list --json`/s');
    expect($issue)->toContain('Never require Incus.');
    expect($issue)->not->toContain('Proof venue: incus', 'smallest registered profile');
    expect($review)->toMatch('/optional Incus diagnostic evidence.*profile, generation, topology identity/s');
    expect($worker)->toMatch('/after the\s+worktree exists.*synchronize.*Release it.*before `post_proof`/s');
    expect($merge)->toMatch('/status: merged\|blocked/');
    expect($merge)->toMatch('/cleanup:\s+action: none/s');
    expect($merge)->toMatch('/fingerprints merged\s+`main`.*refreshes.*only when prepared state\s+changed/s');
    expect($merge)->toMatch('/failed refresh\s+blocks worktree removal and issue closure/s');
    expect($pr)->toContain(
        'Candidate SHA and tree:',
        'Incus generation/topology identity (if used):',
        'Checkout roles (if used):',
    );
    expect($rootGuidance)->toMatch('/refreshes\s+the stopped Incus standby.*`failed` refresh leaves both pending/s');
    expect($workflow)->toMatch('/merged_refresh_blocked.*worktree removal and issue closure pending/s');
    expect($workflow)->toMatch('/Lock contention can be\s+retried by the external orchestrator/s');
    expect($workflow)->toMatch('/`unchanged` or `promoted` result.*`bin\/worktree-remove`/s');
    expect($topologies)->toContain('### `gateway_app-dev_app-prod`', 'Registered on 2026-08-29');
    expect($topologies)->toContain('bin/e2e-topology acquire ISSUE WORKTREE');
    expect($topologies)->toContain('bin/e2e-topology sync ISSUE WORKTREE');
    expect($topologies)->toContain('bin/e2e-topology prove ISSUE WORKTREE --candidate-sha=SHA');
    expect($topologies)->toContain('bin/e2e-topology release ISSUE');
    expect($topologies)->toMatch('/--allow-cold.*initial construction.*never replaces.*promoted generation/s');
    expect($topologies)->toContain('reviewed disaster-recovery procedure');
});
