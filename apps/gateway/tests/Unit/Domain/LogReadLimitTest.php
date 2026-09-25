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
});
