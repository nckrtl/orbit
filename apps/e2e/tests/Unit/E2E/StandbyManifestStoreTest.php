<?php

declare(strict_types=1);

use App\E2E\StandbyManifestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\StandbyGeneration;

describe('StandbyManifestStore', function () {
    it('round-trips a typed generation with exact ordered snapshots', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-standby-'.bin2hex(random_bytes(4)));
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
        );
        $store->record($generation);
        $store->promote($generation);

        expect($store->promoted())
            ->toEqual($generation)
            ->and(new AtomicJsonStore($paths)->read('standby/promoted.json'))
            ->toMatchArray([
                'prepared_fingerprint' => str_repeat('b', 64),
                'base_image_fingerprint' => str_repeat('c', 64),
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
            ),
        )
            ->toThrow(InvalidArgumentException::class);

        $paths = new StatePaths(sys_get_temp_dir().'/orbit-standby-'.bin2hex(random_bytes(4)));
        new AtomicJsonStore($paths)->write('standby/promoted.json', ['schema' => 99]);
        expect(fn () => new StandbyManifestStore(new AtomicJsonStore($paths), $paths)->promoted())
            ->toThrow(InvalidArgumentException::class);
    });

    it('retains current, previous, and topology-pinned generations when pruning', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-standby-'.bin2hex(random_bytes(4)));
        $json = new AtomicJsonStore($paths);
        $store = new StandbyManifestStore($json, $paths);
        $generation = fn (string $id, ?string $previous = null): StandbyGeneration => new StandbyGeneration(
            $id,
            str_repeat(substr($id, -1), 40),
            ['gateway' => "main-{$id}", 'app-dev' => "main-{$id}", 'app-prod' => "main-{$id}"],
            str_repeat('a', 64),
            str_repeat('b', 64),
            new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
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
});
