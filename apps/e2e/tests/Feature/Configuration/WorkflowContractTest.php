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

    expect($issue)->toMatch('/Use `automated`.*Set both Incus fields to\s+`none`/s');
    expect($issue)->toMatch('/Proof venue: incus.*selected\s+profile and exact checkout roles/s');
    expect($issue)->toMatch('/Keep the issue in preparation until.*registered/s');
    expect($issue)->toContain('gateway_app-dev_app-prod', 'ordered roles', '`gateway`, `app-dev`, `app-prod`');
    expect($issue)->not->toContain('smallest registered profile');
    expect($review)->toContain('`gateway_app-dev_app-prod` profile');
    expect($worker)->toMatch('/after the\s+worktree exists.*synchronize.*never releases Incus/s');
    expect($review)->toMatch('/generation and topology identity.*candidate SHA and tree/s');
    expect($merge)->toMatch('/status: merged\|merged_refresh_blocked\|blocked/');
    expect($merge)->toMatch('/merged_refresh_blocked.*cleanup.*none/s');
    expect($merge)->toMatch('/fingerprints.*refreshes.*only when.*changed/s');
    expect($merge)->toMatch('/release Incus.*before the worktree.*closes the issue/s');
    expect($pr)->toContain(
        'Candidate SHA and tree:',
        'Incus generation/topology identity (if used):',
        'Checkout roles (if used):',
    );
    expect($rootGuidance)->toMatch(
        '/releases Incus first, then the worktree, and closes\s+only after unchanged or promoted/s',
    );
    expect($workflow)->toMatch('/merged_refresh_blocked.*cleanup untouched.*does not close/s');
    expect($workflow)->toMatch('/releases the disposable Incus\s+topology first.*runs `bin\/worktree-remove`/s');
    expect($topologies)->toContain('Registered profiles: None.');
    expect($topologies)->toMatch('/acquire gateway_app-dev_app-prod --issue <ISSUE>/');
    expect($topologies)->toMatch('/sync gateway_app-dev_app-prod --worktree <WORKTREE> --role <ROLE>/');
    expect($topologies)->toMatch('/prove gateway_app-dev_app-prod --sha <CANDIDATE_SHA> --tree <TREE>/');
    expect($topologies)->toMatch('/prove.*\n.*and `bin\/incus-profile release gateway_app-dev_app-prod`/s');
});
