<?php

declare(strict_types=1);

use App\Infrastructure\AppInstances\BunDependencyUpdateProgram;
use App\Infrastructure\AppInstances\ComposerDependencyUpdateProgram;
use App\Infrastructure\AppInstances\DependencyUpdateSupervisorHost;
use App\Infrastructure\AppInstances\NpmDependencyUpdateProgram;

dataset('dependency update programs', [
    'bun' => BunDependencyUpdateProgram::class,
    'npm' => NpmDependencyUpdateProgram::class,
    'composer' => ComposerDependencyUpdateProgram::class,
]);

it('gives Nodes the program with Ubuntu commands and /proc owner checks', function (string $program): void {
    $node = $program::render();

    expect($program::render(DependencyUpdateSupervisorHost::Node))->toBe($node)
        ->and($node)->toContain('/usr/bin/setsid --wait /usr/bin/bash -eu -c')
        ->and(substr_count($node, 'if [ ! -d "/proc/$owner" ]; then'))->toBe(2)
        ->and(DependencyUpdateSupervisorHost::Node->launcher())
        ->toBe(['/usr/bin/setsid', '--wait', '/usr/bin/bash', '-eu', '-c']);
})->with('dependency update programs');

it('replaces only host commands and process-state reads in the portable program', function (string $program): void {
    $node = $program::render();
    $portable = $program::render(DependencyUpdateSupervisorHost::Portable);

    expect($portable)->not->toContain('/proc/')
        ->and($portable)->not->toContain('/usr/bin/')
        ->and(substr_count($portable, 'state=$(ps -o state= -p "$owner" 2>/dev/null | cut -c1 || true)'))->toBe(2)
        ->and(str_replace(['/usr/bin/setsid ', '/usr/bin/bash ', '/usr/bin/composer '], ['setsid ', 'bash ', 'composer '], $node))
        ->toContain('owner_gone() {')
        ->and(count(explode("\n", $node)) - count(explode("\n", $portable)))->toBe(6);
})->with('dependency update programs');
