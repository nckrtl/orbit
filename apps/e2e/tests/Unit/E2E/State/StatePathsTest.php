<?php

declare(strict_types=1);

use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use Symfony\Component\Process\Process;

describe('StatePaths', function () {
    it('keeps host state in the primary checkout and issue state in the worktree', function () {
        $base = temporaryPath('orbit-paths-', 4);
        mkdir($base.'/primary/.worktrees/tst-1-slug', 0700, true);
        $primary = StatePaths::forPrimary($base.'/primary/');
        $worktree = StatePaths::forWorktree($base.'/primary/.worktrees/tst-1-slug');

        expect($primary->root())
            ->toBe($base.'/primary/.e2e')
            ->and($worktree->root())
            ->toBe($base.'/primary/.worktrees/tst-1-slug/.e2e')
            ->and(fileperms($primary->root()) & 0777)
            ->toBe(0700);
    });

    it('keeps the ACL mask of an existing state root', function (): void {
        $base = temporaryPath('orbit-paths-acl-', 4);
        mkdir($base.'/state', 0700, true);
        (new Process(['setfacl', '--modify', 'user:'.(posix_geteuid() + 1).':rwx,mask::rwx', $base.'/state']))->mustRun();
        $acl = new Process(['getfacl', '--omit-header', '--numeric', '--no-effective', $base.'/state']);
        $acl->mustRun();
        $before = $acl->getOutput();

        $paths = new StatePaths($base.'/state');
        $acl->mustRun();
        expect($acl->getOutput())->toBe($before)->toContain('mask::rwx');

        $paths->ensureParent('nested/state.json');
        $acl->mustRun();
        expect($acl->getOutput())->toBe($before);
    });

    it('removes owning-group and other access without changing named grants', function (): void {
        $base = temporaryPath('orbit-paths-private-', 4);
        mkdir($base.'/state/nested', 0777, true);
        $grant = 'user:'.(posix_geteuid() + 1).':r-x,group::rwx,mask::r-x,other::rwx';

        foreach ([$base.'/state', $base.'/state/nested'] as $directory) {
            (new Process(['setfacl', '--no-mask', '--modify', $grant, $directory]))->mustRun();
        }

        $paths = new StatePaths($base.'/state');
        $paths->ensureParent('nested/state.json');

        foreach ([$base.'/state', $base.'/state/nested'] as $directory) {
            $acl = new Process(['getfacl', '--omit-header', '--numeric', '--no-effective', $directory]);
            $acl->mustRun();
            expect($acl->getOutput())->toContain('group::---', 'other::---', 'mask::r-x', 'user:'.(posix_geteuid() + 1).':r-x');
        }
    });

    it('makes an existing directory without named grants private', function (): void {
        $base = temporaryPath('orbit-paths-mode-', 4);
        mkdir($base.'/state', 0777, true);
        chmod($base.'/state', 0777);

        $paths = new StatePaths($base.'/state');

        clearstatcache(true, $paths->root());
        expect(fileperms($paths->root()) & 0777)->toBe(0700);
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
        $target = TopologyTarget::feature('TST-321', new AttemptId(str_repeat('a', 32)));

        expect(TopologyProfile::ROLES)
            ->toBe(['gateway', 'app-dev', 'app-prod', 'operator'])
            ->and(TopologyProfile::CHECKOUT_ROLES)
            ->toBe(['gateway', 'app-dev', 'operator'])
            ->and(TopologyProfile::ASSIGNMENTS)
            ->toBe([
                'gateway' => ['gateway', 'vpn', 'websocket', 'router'],
                'app-dev' => ['app-dev', 'metrics', 'database'],
                'app-prod' => ['app-prod', 'ingress'],
            ])
            ->and($target->network())
            ->toBe('oe-9498fa889742')
            ->and($target->instance('app-prod'))
            ->toBe('orbit-e2e-tst-321-aaaaaaaa-app-prod');
    });
});
