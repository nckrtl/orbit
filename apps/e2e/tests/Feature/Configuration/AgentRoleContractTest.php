<?php

declare(strict_types=1);

$root = dirname(__DIR__, 5);

$read = static fn (string $relative): string => (string) file_get_contents($root.'/'.$relative);

it('reads complete worktree listings without early-exit SIGPIPE', function () use ($read): void {
    foreach ([$read('bin/worktree-create'), $read('bin/worktree-remove')] as $script) {
        expect($script)->not->toContain("awk '/^worktree / { print substr(\$0, 10); exit }'");
        expect($script)->toContain("awk '/^worktree / && !found { print substr(\$0, 10); found=1 }'");
        expect($script)->not->toContain('grep -Fxq "worktree $worktree"');
        expect($script)->toContain('grep -Fx "worktree $worktree" >/dev/null');
    }
});

it('refuses active successful proof and releases diagnosis or discovery only after merge', function () use (
    $read,
): void {
    $script = $read('bin/worktree-remove');

    expect($script)
        ->toContain('if [[ -f "$worktree/.e2e/proof-attempt.json" ]]')
        ->toContain('($result["status"] ?? null) === "proved"')
        ->toContain('($result["attempt_id"] ?? null) === ($attempt["attempt_id"] ?? null)')
        ->toContain('$worktree/.e2e/captured-proof')
        ->toContain('must be released by verified closeout before worktree removal')
        ->toContain('Only diagnosis remains releasable here')
        ->toContain('release "$linear_id" "--worktree=$worktree" --proof')
        ->toContain('if [[ -f "$worktree/.e2e/attempt.json" ]]')
        ->toContain('release "$linear_id" "--worktree=$worktree"');

    expect($script)
        ->toContain('awk -v ref="branch refs/heads/$branch"')
        ->toContain('[[ -n "$worktree" ]] || worktree="$worktree_root/$name"');

    $mergeCheck = strpos($script, 'git merge-base --is-ancestor "$branch" origin/main');
    $proofRelease = strpos($script, 'release "$linear_id" "--worktree=$worktree" --proof');

    expect($mergeCheck)
        ->not->toBeFalse()->and($proofRelease)
        ->not->toBeFalse()->and($mergeCheck)->toBeLessThan($proofRelease);
});

it('documents physical Node targeting for extended issue topologies', function () use ($read): void {
    $script = $read('bin/e2e-topology');

    expect($script)
        ->toContain('recorded physical Node key')
        ->toContain('including app-prod-2 when declared');
});
