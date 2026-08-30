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
    // Automated-only path: explicit summary, step-7 gates on both paths, direct-work threshold
    expect($worker)->toMatch(
        '/## Automated-only path\n\nAn issue without a `Proof: incus` line runs steps 2, 7, and 10, skips steps 3\s+to 9, and returns the handoff with `venue: automated`/',
    );
    expect($worker)->toMatch('/Review corrections continue from step 11/');
    expect($worker)->toMatch(
        '/These gates \(test-driven\s+development, `composer check`, `bin\/test`, and the commit freeze\) apply on\s+both the Incus path and the automated-only path/',
    );
    expect($worker)->toMatch(
        '/Do the work directly when the change touches at most 5 files or stays inside\s+a single project/',
    );
    expect($worker)->toMatch(
        '/Inspect\s+`\/home\/nckrtl\/orbit-old` only when the issue reimplements prior product\s+behavior/',
    );
    expect($worker)->toMatch(
        '/Mago report at level `error` fails `composer check`; lower\s+levels \(`warning`, `help`, `note`\) are advisory/',
    );
    // Delegation is client-neutral: no model or client-option pins
    expect($worker)->toMatch('/client\'s available reasoning model/');
    foreach ([$worker, $review, $merge, $workflow] as $document) {
        foreach (['gpt-5.6-luna', 'fork_turns', 'Luna Light', 'reasoning_effort'] as $pin) {
            expect($document)->not->toContain($pin);
        }
    }
    // Worker-owned discovery release and proof; the project manager keeps post-merge cleanup only
    expect($worker)->toMatch(
        '/Remove discovery\.\*\* The worker runs\s+`bin\/e2e-topology release ISSUE ATTEMPT --json`/',
    );
    expect($worker)->toMatch('/worker runs the one-shot proof command/');
    expect($worker)->toMatch('/project manager keeps post-merge\s+cleanup only/');
    expect($worker)->not->toMatch('/Request (discovery )?release/');
    expect($review)->toMatch('/feature worker\s+runs discovery `release` and `prove` for its own issue/');
    expect($merge)->toMatch('/feature worker runs discovery `release` and `prove` for its own issue/');
    expect($merge)->toMatch('/project manager keeps post-merge cleanup only/');
    expect($workflow)->toMatch('/Remove discovery\.\*\* The worker runs/');
    // The CLI resolves by name on the guest PATH; an absent attempt is named as such
    expect($topologies)->toContain('/usr/local/bin/orbit', 'ISSUE has no active attempt.');
    expect($worker)->toContain('--argv=\'["orbit","doctor","--json"]\'', 'ISSUE has no active attempt.');
    expect($topologies)->not->toContain('The Orbit CLI is not on that `PATH`');
    expect($worker)->not->toContain('the Orbit CLI is not on that');

    // Harness-touching diffs: both live suites through bin/e2e-live, blocking without evidence
    expect($worker)->toMatch(
        '/when the diff touches\s+`apps\/e2e\/app\/\*\*`, `apps\/e2e\/resources\/guest\/\*\*`, `apps\/e2e\/tests\/Live\/\*\*`,\s+or `bin\/e2e-\*`/',
    );
    expect($worker)->toContain('tests/Live/TopologyLedLifecycleAcceptanceTest.php');
    expect($worker)->toContain('tests/Live/RollingTopologyAcceptanceTest.php');
    expect($worker)->toMatch('/validation clone\s+whose `main` is the frozen candidate/');
    expect($worker)->toContain('bin/e2e-live <candidate-sha> --rolling');
    expect($worker)->toMatch(
        '/record the command,\s+assertion count, and duration of each suite in the handoff `checks` and\s+the pull request body/',
    );
    expect($review)->toMatch('/harness-touching diff without that evidence is a blocking finding/');
    expect($review)->toContain('bin/e2e-live <candidate-sha> --rolling');
    expect($merge)->toMatch('/harness-touching diff without that evidence blocks the merge/');
    expect($merge)->toContain('bin/e2e-live <candidate-sha> --rolling');
    expect($workflow)->toContain('bin/e2e-live <candidate-sha>');
    expect($topologies)->toContain('bin/e2e-live SHA [--rolling]', '### `bin/e2e-live`', 'ORBIT_E2E_VALIDATE_ROOT');
    expect($pr)->toContain('bin/e2e-live <sha> --rolling');

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
    // Exec: inline argv, mutually exclusive with the file, resolved through env on the guest PATH
    expect($topologies)->toContain('--argv=', 'argv[0]', 'Passing both is refused');
    expect($worker)->toContain('--argv=', 'mutually exclusive', 'absolute path');
    // Proof fixtures: per-issue host directory staged to one fixed guest path on every role
    expect($topologies)->toContain('apps/e2e/resources/proof/<issue>/', '/var/lib/orbit-e2e/proof/', 'proof_fixtures');
    expect($worker)->toContain('apps/e2e/resources/proof/<issue>/', '/var/lib/orbit-e2e/proof/', 'proof_fixtures');
    expect($review)->toContain('/var/lib/orbit-e2e/proof/', 'proof_fixtures');
    // Proof output: no plan echo, diagnosis ends with the failed action
    expect($topologies)->toContain('failed_action', 'stdout_tail', 'stderr_tail');
    expect($worker)->toContain('failed_action');
    expect($worker)->toMatch('/diagnosis round.*moves the code freeze/s');
    expect($worker)->toMatch('/proof plan.*may change between\s+rounds/s');
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
