<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\StandbyManifestStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\TopologyProfile;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

describe('StandbyManifestStore', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('round-trips a typed generation with exact ordered snapshots', function () {
        $paths = new StatePaths(temporaryPath('orbit-standby-', 4));
        $store = new StandbyManifestStore($json = new AtomicJsonStore($paths), $paths, new IncusHost);
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
        expect(
            fn () => new StandbyManifestStore(
                $json = new AtomicJsonStore($paths),
                $paths,
                new IncusHost,
            )->promoted(),
        )
            ->toThrow(InvalidArgumentException::class);
    });

    it('retains current, previous, and topology-pinned generations when pruning', function () {
        $paths = new StatePaths(temporaryPath('orbit-standby-', 4));
        $json = new AtomicJsonStore($paths);
        $store = new StandbyManifestStore($json, $paths, new IncusHost);
        $old = standbyPruneGeneration('g1');
        $pinned = standbyPruneGeneration('g2');
        $previous = standbyPruneGeneration('g3');
        $current = standbyPruneGeneration('g4', 'g3');
        foreach ([$old, $pinned, $previous, $current] as $item) {
            $json->write("standby/generations/{$item->id}.json", $item->toArray());
        }
        pinnedTopologyState($pinned);

        expect(array_map(fn (StandbyGeneration $item): string => $item->id, $store->prunable($current)))
            ->toBe(['g1']);
    });

    it('never prunes the generation a live topology attempt pins', function () {
        $paths = new StatePaths(temporaryPath('orbit-standby-', 4));
        $json = new AtomicJsonStore($paths);
        $store = new StandbyManifestStore($json, $paths, new IncusHost);
        $pinned = standbyPruneGeneration('g1');
        $current = standbyPruneGeneration('g2');
        foreach ([$pinned, $current] as $item) {
            $json->write("standby/generations/{$item->id}.json", $item->toArray());
        }
        pinnedTopologyState($pinned);

        expect($store->prunable($current))->toBeEmpty();
    });

    it('fails closed when a manifest collection cannot be inspected', function (string $collection) {
        $paths = new StatePaths(temporaryPath('orbit-standby-', 4));
        $json = new AtomicJsonStore($paths);
        $store = new StandbyManifestStore($json, $paths, new IncusHost);
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
    })->with(['standby/generations']);

    it('fails closed when a manifest collection is a broken symbolic link', function (string $collection) {
        $paths = new StatePaths(temporaryPath('orbit-standby-', 4));
        $store = new StandbyManifestStore($json = new AtomicJsonStore($paths), $paths, new IncusHost);
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
    })->with(['standby/generations']);
});

/** Fake one live harness VM whose metadata pins the given generation. */
function pinnedTopologyState(StandbyGeneration $generation, string $issue = 'NCK-123'): void
{
    $target = featureTarget($issue);
    Process::fake([
        '*' => Process::result(json_encode([[
            'name' => $target->instance('gateway'),
            'type' => 'virtual-machine',
            'status' => 'Running',
            'status_code' => 103,
            'config' => [
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.issue' => $issue,
                'user.orbit.e2e.generation' => $generation->id,
            ],
            'devices' => ['root' => ['pool' => 'default'], 'eth0' => ['network' => $target->network()]],
        ]], JSON_THROW_ON_ERROR)),
    ]);
}

function standbyPruneGeneration(string $id, ?string $previous = null): StandbyGeneration
{
    return new StandbyGeneration(
        $id,
        str_repeat(substr($id, offset: -1), 40),
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
}
