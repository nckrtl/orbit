<?php

declare(strict_types=1);

use App\E2E\WorktreeLocator;

describe('WorktreeLocator', function () {
    it('finds the one worktree named after the issue under the primary checkout', function () {
        $primary = temporaryPath('orbit-locator-', 4);
        mkdir($primary.'/.worktrees/nck-12-feature', 0700, true);
        mkdir($primary.'/.worktrees/nck-120-other', 0700, true);
        $request = new WorktreeLocator($primary)->locate('NCK-12');

        expect($request->issue)
            ->toBe('NCK-12')
            ->and($request->worktree)
            ->toBe(realpath($primary.'/.worktrees/nck-12-feature'));
    });

    it('prefers an explicit worktree and refuses zero or many candidates', function () {
        $primary = temporaryPath('orbit-locator-', 4);
        mkdir($primary.'/.worktrees/nck-12-a', 0700, true);
        mkdir($primary.'/.worktrees/nck-12-b', 0700, true);
        $locator = new WorktreeLocator($primary);

        expect($locator->locate('NCK-12', $primary.'/.worktrees/nck-12-b')->worktree)
            ->toBe(realpath($primary.'/.worktrees/nck-12-b'))
            ->and(fn () => $locator->locate('NCK-12'))
            ->toThrow(RuntimeException::class, 'More than one worktree matches')
            ->and(fn () => $locator->locate('NCK-13'))
            ->toThrow(RuntimeException::class, 'No worktree matches '.$primary.'/.worktrees/nck-13-*')
            ->and(fn () => $locator->locate('bad issue'))
            ->toThrow(InvalidArgumentException::class);
    });
});
