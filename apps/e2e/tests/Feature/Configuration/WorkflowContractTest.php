<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('keeps the topology-led workflow contracts aligned', function (): void {
    $root = dirname(__DIR__, 5);
    $issue = File::get($root.'/.agents/skills/creating-orbit-issues/SKILL.md');
    $worker = File::get($root.'/.agents/skills/developing-orbit-features/SKILL.md');
    $review = File::get($root.'/.agents/skills/reviewing-orbit-pull-requests/SKILL.md');
    $merge = File::get($root.'/.agents/skills/merging-orbit-pull-requests/SKILL.md');
    $pr = File::get($root.'/.github/pull_request_template.md');
    $rootGuidance = File::get($root.'/AGENTS.md');
    $workflow = File::get($root.'/docs/reference/development-workflow.md');
    $topologies = File::get($root.'/docs/reference/incus-topologies.md');

    // Incus issue: Proof: incus plus Composition: gateway + app-dev + app-prod
    expect($issue)->toContain("Proof: incus\nComposition: gateway + app-dev + app-prod");
    // Automated issue: no Proof or Composition lines
    expect($issue)->toMatch('/Omit both lines for\s+automated-only work/');
    expect($issue)->toMatch('/cannot use an\s+unsupported/');
    expect($issue)->toMatch('/Gateway support.*Gateway tests.*E2E recipe.*harness support.*live acceptance/s');

    // Worker: discovery -> cleanup -> fresh proof -> normal PR
    expect($worker)->toMatch('/Create discovery.*Remove discovery.*Prove fresh.*Open a normal pull request/s');
    expect($worker)->toMatch('/waits? for verified\s+absence before proof/si');
    expect($worker)->toContain('bin/e2e-topology prove ISSUE WORKTREE --candidate-sha=SHA --proof-plan-file=PATH');
    expect($worker)->toMatch('/status: review_ready\|changes_addressed\|blocked/');
    expect($worker)->toMatch('/venue: automated\|incus/');
    expect($worker)->toMatch('/topology: gateway_app-dev_app-prod\|null/');
    expect($worker)->toMatch('/status: proved\|not-applicable/');
    expect($worker)->toMatch('/CI fields may\s+remain `null` while CI runs/');
    expect($worker)->toMatch('/subagents/');

    // Reviewer: review can approve while CI is pending
    expect($review)->toMatch('/approve while CI is\s+pending/');
    expect($review)->toMatch('/checks: pass\|pending\|fail\|not-assessed/');
    expect($review)->toMatch('/status: approved\|changes_requested\|blocked/');
    expect($review)->toMatch('/no mutation|performs no mutation/i');

    // Merge: active proved attempt + current candidate + passing current-head CI
    expect($merge)->toMatch('/status: merged\|blocked/');
    expect($merge)->toMatch('/active proof topology.*`proved`.*equals the current pull-request head/s');
    expect($merge)->toMatch('/passing\s+current-head CI/');
    expect($merge)->toMatch('/performs\s+no cleanup/');

    // Cleanup: proof -> prepared refresh -> worktree -> issue close
    expect($merge)->toMatch('/order: release_proof_then_refresh_then_worktree_then_issue/');
    expect($merge)->toMatch('/does not\s+revert merged code/');
    expect($workflow)->toMatch(
        '/releases the proof topology.*verifies.*absence.*fingerprint.*worktree.*closes the.*issue/s',
    );
    expect($rootGuidance)->toMatch('/releases the proof topology.*refresh.*worktree.*issue/s');

    expect($pr)->toContain(
        'Candidate SHA:',
        'Topology:',
        'Attempt ID:',
        'Proof status:',
        'Post-deployment actions:',
    );

    expect($topologies)->toContain('### `gateway_app-dev_app-prod`', 'Ubuntu 26.04');
    expect($topologies)->toContain('bin/e2e-topology acquire ISSUE WORKTREE');
    expect($topologies)->toContain('bin/e2e-topology sync ISSUE ATTEMPT WORKTREE');
    expect($topologies)->toContain('bin/e2e-topology verify ISSUE ATTEMPT');
    expect($topologies)->toContain('bin/e2e-topology exec ISSUE ATTEMPT ROLE --argv-file=PATH');
    expect($topologies)->toContain('bin/e2e-topology prove ISSUE WORKTREE --candidate-sha=SHA --proof-plan-file=PATH');
    expect($topologies)->toContain('bin/e2e-topology diagnose ISSUE ATTEMPT');
    expect($topologies)->toContain('bin/e2e-topology status ISSUE [ATTEMPT]');
    expect($topologies)->toContain('bin/e2e-topology release ISSUE ATTEMPT');
    expect($topologies)->toContain('bin/e2e-topology reap --issue-state-file=PATH');
    expect($topologies)->toContain('/home/orbit/orbit');
    expect($topologies)->toMatch('/[Pp]roof never\s+mounts/');
    expect($topologies)->not->toMatch('/unregistered|No registered profiles is acceptable/');

    $absent = [
        'pre_rollout',
        'post_proof',
        'rollout_approved',
        'Proof venue:',
        'Live topology:',
        'Live nodes:',
        'Rollout approved.',
        '--draft',
        'as a draft',
        'shared live node',
        'restore the documented pre-state',
    ];

    foreach ([$issue, $worker, $review, $merge, $pr, $workflow, $topologies] as $document) {
        foreach ($absent as $phrase) {
            expect($document)->not->toContain($phrase);
        }
    }

    expect($rootGuidance)->not->toContain('pre_rollout', 'post_proof', 'rollout_approved', 'Live nodes:');
});
