<?php

declare(strict_types=1);

use App\Domain\Hibernation\LocalRuntimeDependencies;

it('treats vendor as reconstructable when composer.json and composer.lock are regular files', function (): void {
    $state = LocalRuntimeDependencies::inspect(
        composerJsonFile: true,
        composerLockFile: true,
        vendorPresent: true,
        vendorSymlink: false,
        packageJsonFile: false,
        javascriptLockFiles: [],
        nodeModulesPresent: false,
        nodeModulesSymlink: false,
    );

    expect($state->prunableVendor())
        ->toBeTrue()
        ->and($state->restorableVendor())
        ->toBeFalse();
});

it('refuses a vendor symlink even when Composer lockfiles exist', function (): void {
    $state = LocalRuntimeDependencies::inspect(
        composerJsonFile: true,
        composerLockFile: true,
        vendorPresent: true,
        vendorSymlink: true,
        packageJsonFile: false,
        javascriptLockFiles: [],
        nodeModulesPresent: false,
        nodeModulesSymlink: false,
    );

    expect($state->prunableVendor())
        ->toBeFalse()
        ->and($state->restorableVendor())
        ->toBeFalse();
});

it('treats node_modules as reconstructable for exactly one JavaScript lock family', function (array $locks, bool $expected): void {
    $state = LocalRuntimeDependencies::inspect(
        composerJsonFile: false,
        composerLockFile: false,
        vendorPresent: false,
        vendorSymlink: false,
        packageJsonFile: true,
        javascriptLockFiles: $locks,
        nodeModulesPresent: true,
        nodeModulesSymlink: false,
    );

    expect($state->prunableNodeModules())->toBe($expected);
})->with([
    'npm' => [['package-lock.json'], true],
    'yarn' => [['yarn.lock'], true],
    'pnpm' => [['pnpm-lock.yaml'], true],
    'bun lock' => [['bun.lock'], true],
    'bun lockb' => [['bun.lockb'], true],
    'bun both' => [['bun.lock', 'bun.lockb'], true],
    'npm and yarn' => [['package-lock.json', 'yarn.lock'], false],
    'none' => [[], false],
]);

it('marks missing reconstructable trees as restorable and keeps lockfiles out of the prune set', function (): void {
    $state = LocalRuntimeDependencies::inspect(
        composerJsonFile: true,
        composerLockFile: true,
        vendorPresent: false,
        vendorSymlink: false,
        packageJsonFile: true,
        javascriptLockFiles: ['package-lock.json'],
        nodeModulesPresent: false,
        nodeModulesSymlink: false,
    );

    expect($state->hasPrunable())
        ->toBeFalse()
        ->and($state->restorableVendor())
        ->toBeTrue()
        ->and($state->restorableNodeModules())
        ->toBeTrue();
});
