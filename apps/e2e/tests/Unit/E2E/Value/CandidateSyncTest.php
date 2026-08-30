<?php

declare(strict_types=1);

use App\E2E\Value\CandidateSync;

describe('CandidateSync', function (): void {
    it('records the exact candidate identity a proof synchronized', function (): void {
        $sync = new CandidateSync(str_repeat('a', 40), str_repeat('b', 40), str_repeat('c', 64), str_repeat('d', 32));

        expect($sync->toArray())
            ->toBe([
                'candidate_sha' => str_repeat('a', 40),
                'candidate_tree' => str_repeat('b', 40),
                'guest_script_hash' => str_repeat('c', 64),
                'operation_id' => str_repeat('d', 32),
            ])
            ->and(CandidateSync::fromArray($sync->toArray()))
            ->toEqual($sync);
    });

    it('rejects an inexact field', function (string $sha, string $tree, string $scripts, string $operation): void {
        expect(fn () => new CandidateSync($sha, $tree, $scripts, $operation))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'short sha' => [str_repeat('a', 39), str_repeat('b', 40), str_repeat('c', 64), str_repeat('d', 32)],
        'uppercase sha' => [str_repeat('A', 40), str_repeat('b', 40), str_repeat('c', 64), str_repeat('d', 32)],
        'short tree' => [str_repeat('a', 40), str_repeat('b', 39), str_repeat('c', 64), str_repeat('d', 32)],
        'short script hash' => [str_repeat('a', 40), str_repeat('b', 40), str_repeat('c', 63), str_repeat('d', 32)],
        'bad operation' => [str_repeat('a', 40), str_repeat('b', 40), str_repeat('c', 64), str_repeat('g', 32)],
    ]);

    it('rejects a serialized record with other keys or types', function (array $value): void {
        expect(fn () => CandidateSync::fromArray($value))->toThrow(InvalidArgumentException::class);
    })->with([
        'missing key' => [[
            'candidate_sha' => str_repeat('a', 40),
            'candidate_tree' => str_repeat('b', 40),
            'guest_script_hash' => str_repeat('c', 64),
        ]],
        'extra key' => [[
            'candidate_sha' => str_repeat('a', 40),
            'candidate_tree' => str_repeat('b', 40),
            'guest_script_hash' => str_repeat('c', 64),
            'operation_id' => str_repeat('d', 32),
            'dirty' => false,
        ]],
        'non-string' => [[
            'candidate_sha' => 1,
            'candidate_tree' => str_repeat('b', 40),
            'guest_script_hash' => str_repeat('c', 64),
            'operation_id' => str_repeat('d', 32),
        ]],
    ]);
});
