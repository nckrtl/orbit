<?php

declare(strict_types=1);

use App\E2E\StandbyManifestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\StandbyGeneration;

describe('StandbyManifestStore', function () {
    it('round-trips a typed generation with exact ordered snapshots', function () {
        $paths = new StatePaths(temporaryPath('orbit-standby-', 4));
        $store = new StandbyManifestStore(new AtomicJsonStore($paths), $paths);
        $generation = new StandbyGeneration(
            'g1',
            str_repeat('a', 40),
            [
                'gateway' => 'main-g1-gateway',
                'app-dev' => 'main-g1-app-dev',
                'app-prod' => 'main-g1-app-prod',
            ],
            str_repeat('b', 64),
            str_repeat('c', 64),
            new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
            str_repeat('d', 64),
            1,
            'ubuntu-26.04-amd64-v1',
            'orbit-base-ubuntu-26.04-runtime',
            'gateway_app-dev_app-prod',
            ['gateway', 'app-dev', 'app-prod'],
            ['gateway', 'app-dev'],
        );
        $store->record($generation);
        $store->promote($generation);

        expect($store->promoted())
            ->toEqual($generation)
            ->and($store->recorded())
            ->toEqual([$generation])
            ->and(new AtomicJsonStore($paths)->read('standby/promoted.json'))
            ->toMatchArray([
                'schema' => 4,
                'prepared_fingerprint' => str_repeat('b', 64),
                'base_image_fingerprint' => str_repeat('c', 64),
                'structural_fingerprint' => str_repeat('d', 64),
                'prepared_schema' => 1,
                'cold_epoch' => 'ubuntu-26.04-amd64-v1',
                'base_image_alias' => 'orbit-base-ubuntu-26.04-runtime',
                'topology' => [
                    'profile' => 'gateway_app-dev_app-prod',
                    'roles' => ['gateway', 'app-dev', 'app-prod'],
                    'checkout_roles' => ['gateway', 'app-dev'],
                ],
                'previous_generation_id' => null,
            ])
            ->and(file_exists($paths->path('standby/promoted-fingerprint.json')))
            ->toBeFalse();
    });

    it('rejects missing roles and persisted schema drift', function () {
        expect(
            fn () => new StandbyGeneration(
                'g1',
                str_repeat('a', 40),
                ['gateway' => 'main-gateway'],
                str_repeat('b', 64),
                str_repeat('c', 64),
                new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
                str_repeat('d', 64),
                1,
                'ubuntu-26.04-amd64-v1',
                'orbit-base-ubuntu-26.04-runtime',
                'gateway_app-dev_app-prod',
                ['gateway', 'app-dev', 'app-prod'],
                ['gateway', 'app-dev'],
            ),
        )
            ->toThrow(InvalidArgumentException::class);

        $paths = new StatePaths(temporaryPath('orbit-standby-', 4));
        new AtomicJsonStore($paths)->write('standby/promoted.json', ['schema' => 99]);
        expect(fn () => new StandbyManifestStore(new AtomicJsonStore($paths), $paths)->promoted())
            ->toThrow(InvalidArgumentException::class);
    });

    it('retains current, previous, and topology-pinned generations when pruning', function () {
        $paths = new StatePaths(temporaryPath('orbit-standby-', 4));
        $json = new AtomicJsonStore($paths);
        $store = new StandbyManifestStore($json, $paths);
        $generation = fn (string $id, ?string $previous = null): StandbyGeneration => new StandbyGeneration(
            $id,
            str_repeat(substr($id, -1), 40),
            ['gateway' => "main-{$id}", 'app-dev' => "main-{$id}", 'app-prod' => "main-{$id}"],
            str_repeat('a', 64),
            str_repeat('b', 64),
            new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
            str_repeat('d', 64),
            1,
            'ubuntu-26.04-amd64-v1',
            'orbit-base-ubuntu-26.04-runtime',
            'gateway_app-dev_app-prod',
            ['gateway', 'app-dev', 'app-prod'],
            ['gateway', 'app-dev'],
            $previous,
        );
        $old = $generation('g1');
        $pinned = $generation('g2');
        $previous = $generation('g3');
        $current = $generation('g4', 'g3');
        foreach ([$old, $pinned, $previous, $current] as $item) {
            $json->write("standby/generations/{$item->id}.json", $item->toArray());
        }
        $json->write('topologies/NCK-123.json', ['generation' => $pinned->toArray()]);

        expect(array_map(fn (StandbyGeneration $item): string => $item->id, $store->prunable($current)))
            ->toBe(['g1']);
    });

    it('fails closed when a manifest collection cannot be inspected', function (string $collection) {
        $paths = new StatePaths(temporaryPath('orbit-standby-', 4));
        $json = new AtomicJsonStore($paths);
        $store = new StandbyManifestStore($json, $paths);
        $current = new StandbyGeneration(
            'g1',
            str_repeat('a', 40),
            ['gateway' => 'main-g1', 'app-dev' => 'main-g1', 'app-prod' => 'main-g1'],
            str_repeat('b', 64),
            str_repeat('c', 64),
            new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
            str_repeat('d', 64),
            1,
            'ubuntu-26.04-amd64-v1',
            'orbit-base-ubuntu-26.04-runtime',
            'gateway_app-dev_app-prod',
            ['gateway', 'app-dev', 'app-prod'],
            ['gateway', 'app-dev'],
        );
        $paths->ensureParent($collection.'-placeholder');
        file_put_contents($paths->path($collection), 'not a directory');

        expect(fn () => $store->prunable($current))
            ->toThrow(RuntimeException::class, 'manifest collection cannot be inspected');
    })->with(['topologies', 'standby/generations']);

    it('fails closed when a manifest collection is a broken symbolic link', function (string $collection) {
        $paths = new StatePaths(temporaryPath('orbit-standby-', 4));
        $store = new StandbyManifestStore(new AtomicJsonStore($paths), $paths);
        $current = new StandbyGeneration(
            'g1',
            str_repeat('a', 40),
            ['gateway' => 'main-g1', 'app-dev' => 'main-g1', 'app-prod' => 'main-g1'],
            str_repeat('b', 64),
            str_repeat('c', 64),
            new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
            str_repeat('d', 64),
            1,
            'ubuntu-26.04-amd64-v1',
            'orbit-base-ubuntu-26.04-runtime',
            'gateway_app-dev_app-prod',
            ['gateway', 'app-dev', 'app-prod'],
            ['gateway', 'app-dev'],
        );
        $link = $paths->root().'/'.$collection;
        if (! is_dir(dirname($link)) && ! mkdir(dirname($link), 0700, true) && ! is_dir(dirname($link))) {
            throw new RuntimeException('Unable to prepare the symbolic-link fixture.');
        }
        symlink('missing', $link);

        expect(fn () => $store->prunable($current))
            ->toThrow(InvalidArgumentException::class, 'cannot be a symbolic link');
    })->with(['topologies', 'standby/generations']);
});
