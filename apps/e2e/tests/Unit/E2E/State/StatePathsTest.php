<?php

declare(strict_types=1);

use App\E2E\State\StatePaths;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;

describe('StatePaths', function () {
    it('uses XDG state and HOME fallback with private directories', function () {
        $base = sys_get_temp_dir().'/orbit-paths-'.bin2hex(random_bytes(4));
        $xdg = StatePaths::fromEnvironment($base.'/xdg', $base.'/home');
        $fallback = StatePaths::fromEnvironment(null, $base.'/home');

        expect($xdg->root())
            ->toBe($base.'/xdg/orbit/e2e')
            ->and($fallback->root())
            ->toBe($base.'/home/.local/state/orbit/e2e')
            ->and(fileperms($xdg->root()) & 0777)
            ->toBe(0700);
    });

    it('rejects absolute, dot, parent, NUL, backslash, and symbolic-link escapes', function () {
        $base = sys_get_temp_dir().'/orbit-paths-'.bin2hex(random_bytes(4));
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
        $target = new TopologyTarget('NCK-321');

        expect(TopologyProfile::ROLES)
            ->toBe(['gateway', 'app-dev', 'app-prod'])
            ->and(TopologyProfile::CHECKOUT_ROLES)
            ->toBe(['gateway', 'app-dev'])
            ->and($target->network())
            ->toBe('oe-ed6933862e02')
            ->and($target->instance('app-prod'))
            ->toBe('orbit-e2e-nck-321-app-prod');
    });
});
