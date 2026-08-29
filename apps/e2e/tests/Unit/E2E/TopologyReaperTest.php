<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\ProofRecordReader;
use App\E2E\ReleaseReceiptStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyManifestStore;
use App\E2E\TopologyReaper;
use App\E2E\TopologyReleaser;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\IssueStateSnapshot;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofStatus;
use App\E2E\Value\ReleaseResult;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    Process::preventStrayProcesses();
});

function reaperReleaser(AtomicJsonStore $store, StatePaths $paths): TopologyReleaser
{
    return new TopologyReleaser(
        new IncusHost,
        new IncusNetworkLifecycle(new IncusHost),
        new TopologyManifestStore($store, $paths),
        $store,
        $paths,
        new OperationId(str_repeat('a', 32)),
    );
}

function reaperAttempt(string $character = 'a'): AttemptId
{
    return new AttemptId(str_repeat($character, 32));
}

function reaperReceipt(string $issue, AttemptId $attempt): ReleaseResult
{
    return new ReleaseResult(
        'a'.str_repeat('0', 31),
        'b'.str_repeat('0', 31),
        $issue,
        $attempt,
        AttemptPurpose::Discovery,
        [],
        [],
        ['verified:old'],
        '2026-08-29T10:00:00Z',
    );
}

describe('topology reaping input', function () {
    it('rejects an impossible expiry before releasing any topology', function () {
        $paths = new StatePaths(temporaryPath('orbit-reaper-', 8));
        $store = new AtomicJsonStore($paths);
        $store->write('leases/NCK-12.json', [
            'issue' => 'NCK-12',
            'attempt' => reaperAttempt()->value,
            'expires_at' => '2026-99-99T99:99:99Z',
        ]);
        $snapshot = new IssueStateSnapshot(['NCK-12' => 'completed']);

        expect(fn () => new TopologyReaper($store, $paths, reaperReleaser($store, $paths))->reap($snapshot))
            ->toThrow(RuntimeException::class, 'invalid');
    });

    it('rejects a lease that names no exact attempt', function () {
        $paths = new StatePaths(temporaryPath('orbit-reaper-', 8));
        $store = new AtomicJsonStore($paths);
        $store->write('leases/NCK-12.json', ['issue' => 'NCK-12', 'expires_at' => '1970-01-01T00:00:00Z']);

        expect(fn () => new TopologyReaper($store, $paths, reaperReleaser($store, $paths))->reap(
            new IssueStateSnapshot(['NCK-12' => 'completed']),
        ))
            ->toThrow(RuntimeException::class, 'invalid')
            ->and($store->read('leases/NCK-12.json'))
            ->not->toBeNull();
    });

    it('interprets canonical expiry timestamps as UTC', function () {
        $paths = new StatePaths(temporaryPath('orbit-reaper-', 8));
        $store = new AtomicJsonStore($paths);
        $store->write('leases/NCK-12.json', [
            'issue' => 'NCK-12',
            'attempt' => reaperAttempt()->value,
            'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 3600),
        ]);
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');
        try {
            expect(new TopologyReaper($store, $paths, reaperReleaser($store, $paths))->reap(new IssueStateSnapshot([
                'NCK-12' => 'completed',
            ])))->toBe([]);
            expect($store->read('leases/NCK-12.json'))->not->toBeNull();
        } finally {
            date_default_timezone_set($previous);
        }
    });

    it('preflights all leases before finalizing an expired lease', function () {
        $paths = new StatePaths(temporaryPath('orbit-reaper-', 8));
        $store = new AtomicJsonStore($paths);
        $store->write('leases/NCK-12.json', [
            'issue' => 'NCK-12',
            'attempt' => reaperAttempt()->value,
            'expires_at' => '1970-01-01T00:00:00Z',
        ]);
        new ReleaseReceiptStore($store, $paths)->write(reaperReceipt('NCK-12', reaperAttempt()));
        $store->write('leases/NCK-13.json', [
            'issue' => 'NCK-13',
            'attempt' => reaperAttempt()->value,
            'expires_at' => '2026-99-99T99:99:99Z',
        ]);

        expect(fn () => new TopologyReaper($store, $paths, reaperReleaser($store, $paths))->reap(
            new IssueStateSnapshot(['NCK-12' => 'completed', 'NCK-13' => 'completed']),
        ))
            ->toThrow(RuntimeException::class, 'invalid');
        expect($store->read('leases/NCK-12.json'))->not->toBeNull();
    });

    it('records one release failure and continues with later expired issues', function () {
        $paths = new StatePaths(temporaryPath('orbit-reaper-', 8));
        $store = new AtomicJsonStore($paths);
        foreach (['NCK-12', 'NCK-13'] as $issue) {
            $store->write('leases/'.$issue.'.json', [
                'issue' => $issue,
                'attempt' => reaperAttempt()->value,
                'state' => 'ready',
                'operation_id' => str_repeat('a', 32),
                'expires_at' => '1970-01-01T00:00:00Z',
            ]);
        }

        $results = new TopologyReaper($store, $paths, reaperReleaser($store, $paths))->reap(new IssueStateSnapshot([
            'NCK-12' => 'completed',
            'NCK-13' => 'completed',
        ]));

        expect($results)
            ->toHaveCount(0)
            ->and($store->read('reaping-failures/NCK-12.json'))
            ->toMatchArray(['issue' => 'NCK-12', 'attempt' => reaperAttempt()->value])
            ->and($store->read('reaping-failures/NCK-13.json')['issue'] ?? null)
            ->toBe('NCK-13');
    });

    it('releases the exact expired attempt of a terminal issue', function () {
        Process::fake(['*' => Process::result('[]')]);
        $paths = new StatePaths(temporaryPath('orbit-reaper-', 8));
        $store = new AtomicJsonStore($paths);
        foreach (['NCK-12' => 'c', 'NCK-14' => 'd'] as $issue => $character) {
            $store->write('leases/'.$issue.'.json', [
                'schema' => 2,
                'issue' => $issue,
                'attempt' => reaperAttempt($character)->value,
                'state' => 'acquiring',
                'operation_id' => str_repeat('a', 32),
                'expires_at' => '1970-01-01T00:00:00Z',
                'pid' => 999999,
                'process_start_identity' => 'dead-test-owner',
                'acquired_at' => '2020-01-01T00:00:00Z',
            ]);
        }
        // A receipt of another attempt of the same issue never stands in for the expired one.
        new ReleaseReceiptStore($store, $paths)->write(reaperReceipt('NCK-14', reaperAttempt('c')));

        $results = new TopologyReaper($store, $paths, reaperReleaser($store, $paths))->reap(new IssueStateSnapshot([
            'NCK-14' => 'completed',
        ]));

        expect($results)
            ->toHaveCount(1)
            ->and($results[0]->attempt->value)
            ->toBe(reaperAttempt('d')->value)
            ->and($store->read('evidence/releases/NCK-14/'.reaperAttempt('d')->value.'.json'))
            ->toBe($results[0]->toArray())
            ->and($store->read('leases/NCK-14.json'))
            ->toBeNull()
            ->and($store->read('leases/NCK-12.json'))
            ->not
            ->toBeNull()
            ->and($store->read('reaping-failures/NCK-14.json'))
            ->toBeNull();
    });

    it('never releases a proved attempt', function () {
        $paths = new StatePaths(temporaryPath('orbit-reaper-', 8));
        $store = new AtomicJsonStore($paths);
        $store->write('leases/NCK-12.json', [
            'issue' => 'NCK-12',
            'attempt' => reaperAttempt()->value,
            'state' => 'ready',
            'operation_id' => str_repeat('a', 32),
            'expires_at' => '1970-01-01T00:00:00Z',
        ]);
        $store->write('evidence/proofs/NCK-12/'.reaperAttempt()->value.'.json', [
            'status' => ProofStatus::Proved->value,
        ]);
        Process::preventStrayProcesses();

        $results = new TopologyReaper($store, $paths, reaperReleaser($store, $paths), new ProofRecordReader($store))
            ->reap(new IssueStateSnapshot(['NCK-12' => 'completed']));

        expect($results)
            ->toBe([])
            ->and($store->read('leases/NCK-12.json'))
            ->not
            ->toBeNull()
            ->and($store->read('reaping-failures/NCK-12.json'))
            ->toBeNull();
        Process::assertNothingRan();
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

describe('proof records', function () {
    it('reads the proof verdict of one exact attempt', function () {
        $paths = new StatePaths(temporaryPath('orbit-proofs-', 8));
        $store = new AtomicJsonStore($paths);
        $reader = new ProofRecordReader($store);

        expect($reader->status('NCK-12', reaperAttempt()))->toBeNull();

        $store->write('evidence/proofs/NCK-12/'.reaperAttempt()->value.'.json', ['status' => 'diagnosis']);
        $store->write('evidence/proofs/NCK-12/'.reaperAttempt('b')->value.'.json', ['status' => 'proved']);
        $store->write('evidence/proofs/NCK-12/'.reaperAttempt('c')->value.'.json', ['status' => 'unknown']);

        expect($reader->status('NCK-12', reaperAttempt()))
            ->toBe(ProofStatus::Diagnosis)
            ->and($reader->isProved('NCK-12', reaperAttempt()))
            ->toBeFalse()
            ->and($reader->isProved('NCK-12', reaperAttempt('b')))
            ->toBeTrue()
            ->and($reader->status('NCK-12', reaperAttempt('c')))
            ->toBeNull();
    });
});
