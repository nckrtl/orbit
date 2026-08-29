<?php

declare(strict_types=1);

use App\E2E\State\OperationJournal;
use App\E2E\State\StatePaths;
use App\E2E\Value\OperationId;

describe('OperationJournal', function () {
    it('keeps strict append order in private JSONL', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-journal-'.bin2hex(random_bytes(4)));
        $journal = new OperationJournal($paths);
        $operation = new OperationId(str_repeat('a', 32));
        $journal->append($operation, ['sequence' => 1]);
        $journal->append($operation, ['sequence' => 2]);

        expect(array_column($journal->entries($operation), 'sequence'))
            ->toBe([1, 2])
            ->and($journal->entries($operation)[0]['operation_id'])
            ->toBe($operation->value)
            ->and(fileperms($paths->path('journals/'.str_repeat('a', 32).'.jsonl')) & 0777)
            ->toBe(0600);
    });

    it('never persists command standard input', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-journal-'.bin2hex(random_bytes(4)));
        $journal = new OperationJournal($paths);
        $operation = new OperationId(str_repeat('b', 32));

        $journal->append($operation, [
            'argv' => ['install', '/dev/stdin', '/tmp/secret'],
            'stdin' => "unclassified-secret-material\n",
        ]);

        expect($journal->entries($operation)[0])
            ->toMatchArray([
                'argv' => ['install', '/dev/stdin', '/tmp/secret'],
                'stdin' => '[REDACTED]',
            ]);
    });

    it('rejects malformed and empty JSONL records', function (string $contents) {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-journal-'.bin2hex(random_bytes(4)));
        $operation = new OperationId(str_repeat('c', 32));
        $file = $paths->ensureParent('journals/'.str_repeat('c', 32).'.jsonl');
        file_put_contents($file, $contents);

        expect(fn () => new OperationJournal($paths)->entries($operation))->toThrow(RuntimeException::class);
    })->with(["{broken\n", "{}\n\n"]);

    it('rejects a record belonging to another operation', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-journal-'.bin2hex(random_bytes(4)));
        $operation = new OperationId(str_repeat('f', 32));
        $file = $paths->ensureParent('journals/'.$operation->value.'.jsonl');
        file_put_contents($file, json_encode([
            'operation_id' => str_repeat('0', 32),
            'recorded_at' => '2026-08-29T00:00:00Z',
        ], JSON_THROW_ON_ERROR)
            ."\n");

        expect(fn () => new OperationJournal($paths)->entries($operation))
            ->toThrow(RuntimeException::class, 'different operation');
    });

    it('rolls back failed appends to the exact previous offset', function (string $phase) {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-journal-'.bin2hex(random_bytes(4)));
        $operation = new OperationId(str_repeat('d', 32));
        $journal = new OperationJournal($paths);
        $journal->append($operation, ['sequence' => 1]);
        $file = $paths->path('journals/'.str_repeat('d', 32).'.jsonl');
        $before = file_get_contents($file);
        $failing = new OperationJournal($paths, failure: function (string $current) use ($phase): void {
            if ($current === $phase) {
                throw new RuntimeException('injected journal failure');
            }
        });

        expect(fn () => $failing->append($operation, ['sequence' => 2]))
            ->toThrow(RuntimeException::class, 'injected journal failure')
            ->and(file_get_contents($file))
            ->toBe($before)
            ->and(array_column($journal->entries($operation), 'sequence'))
            ->toBe([1]);
    })->with(['after_append_write', 'after_append_persist']);

    it('does not append or leak its lock when private permissions fail', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-journal-'.bin2hex(random_bytes(4)));
        $operation = new OperationId(str_repeat('e', 32));
        $journal = new OperationJournal($paths);
        $journal->append($operation, ['sequence' => 1]);
        $file = $paths->path('journals/'.str_repeat('e', 32).'.jsonl');
        $before = file_get_contents($file);
        $failing = new OperationJournal($paths, failure: fn (string $phase): ?bool => $phase === 'journal_chmod'
            ? false
            : null);

        expect(fn () => $failing->append($operation, ['sequence' => 2]))
            ->toThrow(RuntimeException::class, 'No record was committed')
            ->and(file_get_contents($file))
            ->toBe($before);

        $journal->append($operation, ['sequence' => 3]);
        expect(array_column($journal->entries($operation), 'sequence'))->toBe([1, 3]);
    });
});
