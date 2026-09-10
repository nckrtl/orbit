<?php

declare(strict_types=1);

use App\E2E\WorktreeLocator;
use Symfony\Component\Process\Process;

function locatorPrimary(): string
{
    $primary = temporaryPath('orbit-locator-', 6);
    mkdir($primary, 0700, true);
    foreach ([
        ['init', '-b', 'main'],
        ['config', 'user.name', 'Orbit'],
        ['config', 'user.email', 'orbit@example.test'],
        ['commit', '--allow-empty', '-m', 'fixture'],
    ] as $arguments) {
        new Process(['git', ...$arguments], $primary)->mustRun();
    }

    return $primary;
}

describe('WorktreeLocator', function () {
    it('finds the registered issue worktree outside primary and excludes similar issue IDs', function () {
        $primary = locatorPrimary();
        $worktree = $primary.'-external/tst-12';
        new Process(['git', 'worktree', 'add', '-b', 'tst-12-feature', $worktree], $primary)->mustRun();
        new Process(['git', 'worktree', 'add', '-b', 'tst-120-other', $primary.'-external/tst-120'], $primary)->mustRun();
        $request = new WorktreeLocator($primary)->locate('TST-12');

        expect($request->issue)->toBe('TST-12');
        expect($request->worktree)->toBe(realpath($worktree));
        $moved = $primary.'-renamed with spaces';
        new Process(['git', 'worktree', 'move', $worktree, $moved], $primary)->mustRun();
        expect(new WorktreeLocator($primary)->locate('TST-12')->worktree)->toBe(realpath($moved));
    });

    it('preserves legacy lookup, prefers an explicit path, and refuses zero or many candidates', function () {
        $primary = locatorPrimary();
        $legacy = $primary.'/.worktrees/tst-12-a';
        $external = $primary.'-external/tst-12';
        new Process(['git', 'worktree', 'add', '-b', 'tst-12-a', $legacy], $primary)->mustRun();
        $locator = new WorktreeLocator($primary);
        expect($locator->locate('TST-12')->worktree)->toBe(realpath($legacy));
        new Process(['git', 'worktree', 'add', '-b', 'tst-12-b', $external], $primary)->mustRun();

        expect($locator->locate('TST-12', $external)->worktree)->toBe(realpath($external));
        expect(fn () => $locator->locate('TST-12'))->toThrow(RuntimeException::class, 'More than one worktree matches');
        expect(fn () => $locator->locate('TST-13'))->toThrow(RuntimeException::class, 'No registered worktree matches TST-13');
        expect(fn () => $locator->locate('bad issue'))->toThrow(InvalidArgumentException::class);
    });
});
