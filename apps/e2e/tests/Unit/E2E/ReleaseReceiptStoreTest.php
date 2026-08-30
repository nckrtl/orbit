<?php

declare(strict_types=1);

use App\E2E\ReleaseReceiptStore;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\ReleaseResult;

function receiptFixture(
    string $attempt,
    string $releasedAt,
    AttemptPurpose $purpose = AttemptPurpose::Discovery,
    array $verifiedAbsent = ['oe-x'],
    string $issue = 'NCK-12',
): ReleaseResult {
    return new ReleaseResult(
        str_repeat('a', 32),
        str_repeat('b', 32),
        $issue,
        new AttemptId($attempt),
        $purpose,
        ['deleted:oe-x'],
        [],
        $verifiedAbsent,
        $releasedAt,
    );
}

describe('release receipts', function () {
    it('writes and reads one receipt per exact attempt', function () {
        $paths = new StatePaths(temporaryPath('orbit-receipts-', 8));
        $store = new ReleaseReceiptStore(new AtomicJsonStore($paths), $paths);
        $receipt = receiptFixture(str_repeat('1', 32), '2026-08-29T10:00:00Z');

        $store->write($receipt);

        expect($store->read('NCK-12', new AttemptId(str_repeat('1', 32)))?->toArray())
            ->toBe($receipt->toArray())
            ->and(file_exists($paths->path('evidence/releases/NCK-12/'.str_repeat('1', 32).'.json')))
            ->toBeTrue()
            ->and($store->read('NCK-12', new AttemptId(str_repeat('2', 32))))
            ->toBeNull()
            ->and($store->read('NCK-13', new AttemptId(str_repeat('1', 32))))
            ->toBeNull();
    });

    it('refuses a receipt whose identity does not match its path', function () {
        $paths = new StatePaths(temporaryPath('orbit-receipts-', 8));
        $json = new AtomicJsonStore($paths);
        $json->write(
            'evidence/releases/NCK-12/'.str_repeat('2', 32).'.json',
            receiptFixture(str_repeat('1', 32), '2026-08-29T10:00:00Z')->toArray(),
        );

        expect(fn () => new ReleaseReceiptStore($json, $paths)->read('NCK-12', new AttemptId(str_repeat('2', 32))))
            ->toThrow(RuntimeException::class, 'identity does not match');
    });

    it('returns the newest verified discovery receipt', function () {
        $paths = new StatePaths(temporaryPath('orbit-receipts-', 8));
        $store = new ReleaseReceiptStore(new AtomicJsonStore($paths), $paths);

        expect($store->latestDiscovery('NCK-12'))->toBeNull();

        $store->write(receiptFixture(str_repeat('1', 32), '2026-08-29T10:00:00Z'));
        $store->write(receiptFixture(str_repeat('2', 32), '2026-08-29T12:00:00Z'));
        $store->write(receiptFixture(str_repeat('3', 32), '2026-08-29T13:00:00Z', AttemptPurpose::Proof));
        $store->write(receiptFixture(str_repeat('4', 32), '2026-08-29T14:00:00Z', verifiedAbsent: []));
        $store->write(receiptFixture(str_repeat('5', 32), '2026-08-29T15:00:00Z', issue: 'NCK-13'));

        expect($store->latestDiscovery('NCK-12')?->attempt->value)
            ->toBe(str_repeat('2', 32))
            ->and($store->latestDiscovery('NCK-13')?->attempt->value)
            ->toBe(str_repeat('5', 32));
    });

    it('fails closed on a malformed receipt during inventory', function () {
        $paths = new StatePaths(temporaryPath('orbit-receipts-', 8));
        $json = new AtomicJsonStore($paths);
        $json->write('evidence/releases/NCK-12/'.str_repeat('1', 32).'.json', ['state' => 'released']);

        expect(fn () => new ReleaseReceiptStore($json, $paths)->latestDiscovery('NCK-12'))
            ->toThrow(InvalidArgumentException::class, 'schema is invalid');
    });
});

describe('release result', function () {
    it('round-trips the exact receipt shape', function () {
        $receipt = receiptFixture(str_repeat('1', 32), '2026-08-29T10:00:00Z');

        expect(array_keys($receipt->toArray()))
            ->toBe([
                'state',
                'operation_id',
                'evidence_id',
                'issue',
                'attempt_id',
                'purpose',
                'released',
                'already_absent',
                'verified_absent',
                'networks_reaped',
                'released_at',
            ])
            ->and(ReleaseResult::fromArray($receipt->toArray())->toArray())
            ->toBe($receipt->toArray());
    });

    it('rejects invalid identities and timestamps', function () {
        expect(fn () => receiptFixture(str_repeat('1', 32), '2026-08-29 10:00:00'))
            ->toThrow(InvalidArgumentException::class, 'timestamp')
            ->and(fn () => receiptFixture(str_repeat('1', 32), '2026-08-29T10:00:00Z', issue: 'nope'))
            ->toThrow(InvalidArgumentException::class, 'Linear issue')
            ->and(fn () => ReleaseResult::fromArray(['state' => 'released']))
            ->toThrow(InvalidArgumentException::class, 'schema is invalid');
    });
});
