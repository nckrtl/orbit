<?php

declare(strict_types=1);

use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;

describe('StatePaths', function () {
    it('keeps host state in the primary checkout and issue state in the worktree', function () {
        $base = temporaryPath('orbit-paths-', 4);
        mkdir($base.'/primary/.worktrees/nck-1-slug', 0700, true);
        $primary = StatePaths::forPrimary($base.'/primary/');
        $worktree = StatePaths::forWorktree($base.'/primary/.worktrees/nck-1-slug');

        expect($primary->root())
            ->toBe($base.'/primary/.e2e')
            ->and($worktree->root())
            ->toBe($base.'/primary/.worktrees/nck-1-slug/.e2e')
            ->and(fileperms($primary->root()) & 0777)
            ->toBe(0700);
    });

    it('rejects absolute, dot, parent, NUL, backslash, and symbolic-link escapes', function () {
        $base = temporaryPath('orbit-paths-', 4);
        $paths = new StatePaths($base.'/state');
        mkdir($base.'/outside');
        symlink($base.'/outside', $paths->root().'/escape');

        foreach ([
            '/absolute',
            './dot',
            'a/./b',
            '../parent',
            'a/../b',
            "nul\0byte",
            'back\\slash',
            'escape/file',
        ] as $unsafe) {
            expect(fn () => $paths->path($unsafe))->toThrow(InvalidArgumentException::class);
        }
    });

    it('provides stable exact profile roles and target names', function () {
        $target = TopologyTarget::feature('NCK-321', new AttemptId(str_repeat('a', 32)));

        expect(TopologyProfile::ROLES)
            ->toBe(['gateway', 'app-dev', 'app-prod'])
            ->and(TopologyProfile::CHECKOUT_ROLES)
            ->toBe(['gateway', 'app-dev'])
            ->and(TopologyProfile::ASSIGNMENTS)
            ->toBe([
                'gateway' => ['gateway', 'vpn'],
                'app-dev' => ['app-dev', 'metrics'],
                'app-prod' => ['app-prod'],
            ])
            ->and($target->network())
            ->toBe('oe-3ed34a09f138')
            ->and($target->instance('app-prod'))
            ->toBe('orbit-e2e-nck-321-aaaaaaaa-app-prod');
    });
});
