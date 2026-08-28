<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyManifestStore;
use App\E2E\TopologyReleaser;
use App\E2E\Value\ReleaseResult;

describe('topology release', function () {
    it('returns compact exact release evidence', function () {
        $result = new ReleaseResult(
            str_repeat('a', 32),
            str_repeat('b', 32),
            ['deleted:orbit-e2e-nck-12'],
            ['orbit-e2e-nck-12-app-prod'],
        );

        expect($result->toArray())->toBe([
            'state' => 'released',
            'operation_id' => str_repeat('a', 32),
            'evidence_id' => str_repeat('b', 32),
            'released' => ['deleted:orbit-e2e-nck-12'],
            'already_absent' => ['orbit-e2e-nck-12-app-prod'],
        ]);
    });

    it('refuses cleanup without the exact manifest', function () {
        $root = sys_get_temp_dir().'/orbit-release-'.bin2hex(random_bytes(8));
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        $releaser = new TopologyReleaser(new IncusHost, new TopologyManifestStore($store), $store, $paths);

        expect(fn () => $releaser->release('NCK-12'))
            ->toThrow(RuntimeException::class, 'exact feature topology manifest');
    });

    it('returns already absent evidence after an exact completed release', function () {
        $root = sys_get_temp_dir().'/orbit-release-'.bin2hex(random_bytes(8));
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        $store->write(
            'releases/NCK-12.json',
            new ReleaseResult(
                str_repeat('a', 32),
                str_repeat('b', 32),
                ['deleted:orbit-e2e-nck-12'],
                [],
            )->toArray(),
        );
        $releaser = new TopologyReleaser(new IncusHost, new TopologyManifestStore($store), $store, $paths);

        $result = $releaser->release('NCK-12');

        expect($result->released)
            ->toBe([])
            ->and($result->alreadyAbsent)
            ->toBe(['deleted:orbit-e2e-nck-12'])
            ->and($result->evidenceId)
            ->toBe(str_repeat('b', 32));
    });
});
