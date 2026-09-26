<?php

declare(strict_types=1);

use App\Domain\Logs\LogReadLimit;

describe(LogReadLimit::class, function (): void {
    it('keeps output under the limit as it is', function (): void {
        expect(LogReadLimit::wholeLines("a\nb\n"))->toBe("a\nb\n")
            ->and(LogReadLimit::wholeLines(''))->toBe('');
    });

    it('drops the first line when the limit cut it in the middle', function (): void {
        $line = str_repeat('y', 10_000);
        $full = implode("\n", array_fill(0, 500, $line))."\n";
        $capped = substr($full, -LogReadLimit::Bytes);

        $kept = LogReadLimit::wholeLines($capped);

        expect(strlen($capped))->toBe(LogReadLimit::Bytes)
            ->and(array_unique(explode("\n", rtrim($kept, "\n"))))->toBe([$line])
            ->and(strlen($kept))->toBeLessThan(LogReadLimit::Bytes);
    });

    it('returns nothing when one line fills the whole limit', function (): void {
        expect(LogReadLimit::wholeLines(str_repeat('z', LogReadLimit::Bytes)))->toBe('');
    });

    it('puts journal entries read newest first back in time order with their continuation lines', function (): void {
        $reversed = "T3 c: three\nT2 b: first\n      second\n      third\nT1 a: one\n";

        expect(LogReadLimit::journalInTimeOrder($reversed))->toBe("T1 a: one\nT2 b: first\n      second\n      third\nT3 c: three\n")
            ->and(LogReadLimit::journalInTimeOrder(''))->toBe('');
    });

    it('drops the oldest entry and a cut line when the byte limit stopped the read', function (): void {
        $newest = "T9 n: newest\n";
        $huge = 'T1 big: 0'."\n".implode('', array_map(static fn (int $i): string => "        {$i}\n", range(1, 600_000)));
        $capped = substr($newest.$huge, 0, LogReadLimit::Bytes);

        expect(LogReadLimit::journalInTimeOrder($capped))->toBe($newest)
            ->and(LogReadLimit::journalInTimeOrder("T2 b: two\nT1 a: o"))->toBe("T2 b: two\n");
    });
});
