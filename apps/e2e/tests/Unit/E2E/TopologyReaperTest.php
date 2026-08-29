<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyManifestStore;
use App\E2E\TopologyReaper;
use App\E2E\TopologyReleaser;
use App\E2E\Value\IssueStateSnapshot;
use App\E2E\Value\OperationId;
use App\E2E\Value\ReleaseResult;

describe('topology reaping input', function () {
    it('rejects an impossible expiry before releasing any topology', function () {
        $root = temporaryPath('orbit-reaper-', 8);
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        $store->write('leases/NCK-12.json', [
            'issue' => 'NCK-12',
            'expires_at' => '2026-99-99T99:99:99Z',
        ]);
        $snapshot = new IssueStateSnapshot(['NCK-12' => 'completed']);
        $releaser = new TopologyReleaser(
            new IncusHost,
            new IncusNetworkLifecycle(new IncusHost),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
        );

        expect(fn () => new TopologyReaper($store, $paths, $releaser)->reap($snapshot))
            ->toThrow(RuntimeException::class, 'invalid');
    });

    it('interprets canonical expiry timestamps as UTC', function () {
        $root = temporaryPath('orbit-reaper-', 8);
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        $store->write('leases/NCK-12.json', [
            'issue' => 'NCK-12',
            'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 3600),
        ]);
        $store->write(
            'releases/NCK-12.json',
            new ReleaseResult('a'.str_repeat('0', 31), 'b'.str_repeat('0', 31), [], [])->toArray(),
        );
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');
        try {
            $releaser = new TopologyReleaser(
                new IncusHost,
                new IncusNetworkLifecycle(new IncusHost),
                new TopologyManifestStore($store),
                $store,
                $paths,
                new OperationId(str_repeat('a', 32)),
            );
            expect(new TopologyReaper($store, $paths, $releaser)->reap(new IssueStateSnapshot([
                'NCK-12' => 'completed',
            ])))->toBe([]);
            expect($store->read('leases/NCK-12.json'))->not->toBeNull();
        } finally {
            date_default_timezone_set($previous);
        }
    });

    it('preflights all leases before finalizing an expired lease', function () {
        $root = temporaryPath('orbit-reaper-', 8);
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        $store->write('leases/NCK-12.json', ['issue' => 'NCK-12', 'expires_at' => '1970-01-01T00:00:00Z']);
        $store->write(
            'releases/NCK-12.json',
            new ReleaseResult('a'.str_repeat('0', 31), 'b'.str_repeat('0', 31), [], [])->toArray(),
        );
        $store->write('topologies/NCK-12.json', ['retained' => true]);
        $store->write('leases/NCK-13.json', ['issue' => 'NCK-13', 'expires_at' => '2026-99-99T99:99:99Z']);
        $releaser = new TopologyReleaser(
            new IncusHost,
            new IncusNetworkLifecycle(new IncusHost),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
        );

        expect(fn () => new TopologyReaper($store, $paths, $releaser)->reap(new IssueStateSnapshot([
            'NCK-12' => 'completed',
            'NCK-13' => 'completed',
        ])))
            ->toThrow(RuntimeException::class, 'invalid');
        expect($store->read('leases/NCK-12.json'))
            ->not->toBeNull()->and($store->read('topologies/NCK-12.json'))
            ->not->toBeNull();
    });

    it('records one release failure and continues with later expired issues', function () {
        $root = temporaryPath('orbit-reaper-', 8);
        $paths = new StatePaths($root);
        $store = new AtomicJsonStore($paths);
        foreach (['NCK-12', 'NCK-13'] as $issue) {
            $store->write('leases/'.$issue.'.json', [
                'issue' => $issue,
                'state' => 'ready',
                'operation_id' => str_repeat('a', 32),
                'expires_at' => '1970-01-01T00:00:00Z',
            ]);
        }
        $store->write(
            'releases/NCK-12.json',
            new ReleaseResult(
                'a'.str_repeat('0', 31),
                'b'.str_repeat('0', 31),
                [],
                [],
            )->toArray(),
        );
        $releaser = new TopologyReleaser(
            new IncusHost,
            new IncusNetworkLifecycle(new IncusHost),
            new TopologyManifestStore($store),
            $store,
            $paths,
            new OperationId(str_repeat('a', 32)),
        );

        $results = new TopologyReaper($store, $paths, $releaser)->reap(new IssueStateSnapshot([
            'NCK-12' => 'completed',
            'NCK-13' => 'completed',
        ]));

        expect($results)
            ->toHaveCount(0)
            ->and($store->read('reaping-failures/NCK-12.json')['issue'] ?? null)
            ->toBe('NCK-12')
            ->and($store->read('reaping-failures/NCK-13.json')['issue'] ?? null)
            ->toBe('NCK-13');
    });

    it('requires a private external issue snapshot with exact terminal entries', function () {
        $path = temporaryPath('orbit-issues-', 8).'.json';
        file_put_contents($path, json_encode([
            'schema' => 1,
            'issues' => ['NCK-12' => 'completed'],
        ], JSON_THROW_ON_ERROR));
        chmod(filename: $path, permissions: 0o644);

        expect(fn () => IssueStateSnapshot::fromFile($path))
            ->toThrow(InvalidArgumentException::class, '0600');

        chmod(filename: $path, permissions: 0o600);
        $snapshot = IssueStateSnapshot::fromFile($path);

        expect($snapshot->isTerminal('NCK-12'))->toBeTrue()->and($snapshot->isTerminal('NCK-13'))->toBeFalse();
        unlink($path);
    });

    it('rejects non-terminal issue states', function () {
        expect(fn () => new IssueStateSnapshot(['NCK-12' => 'in_progress']))
            ->toThrow(InvalidArgumentException::class, 'non-terminal');
    });
});
