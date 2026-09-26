<?php

declare(strict_types=1);

use App\Support\Logs\LogTailOverlap;

describe(LogTailOverlap::class, function (): void {
    it('treats every line as new before anything was printed', function (): void {
        $overlap = new LogTailOverlap;

        expect($overlap->hasContext())->toBeFalse()
            ->and($overlap->find(['a', 'b']))->toBe(['a', 'b'])
            ->and($overlap->find([]))->toBe([]);
    });

    it('returns only the lines after the last five printed lines', function (): void {
        $overlap = new LogTailOverlap;
        $overlap->remember(['1', '2', '3', '4', '5', '6', '7']);

        expect($overlap->find(['2', '3', '4', '5', '6', '7', '8', '9']))->toBe(['8', '9'])
            ->and($overlap->find(['3', '4', '5', '6', '7']))->toBe([]);
    });

    it('uses the newest place where the context appears', function (): void {
        $overlap = new LogTailOverlap;
        $overlap->remember(['tick', 'tock']);

        expect($overlap->find(['tick', 'tock', 'x', 'tick', 'tock', 'y']))->toBe(['y']);
    });

    it('continues after a tail that starts with the end of the context', function (): void {
        $overlap = new LogTailOverlap;
        $overlap->remember(['a', 'b', 'c']);

        expect($overlap->find(['b', 'c', 'd', 'e']))->toBe(['d', 'e']);
    });

    it('does not trust a single shared line at the start of the tail', function (): void {
        $overlap = new LogTailOverlap;
        $overlap->remember(['a', 'b', 'c']);

        expect($overlap->find(['c', 'd']))->toBeNull();
    });

    it('finds no overlap when the tail does not contain the context', function (): void {
        $overlap = new LogTailOverlap;
        $overlap->remember(['a', 'b']);

        expect($overlap->find(['x', 'y']))->toBeNull();
    });

    it('finds a single printed line inside a larger window', function (): void {
        $overlap = new LogTailOverlap;
        $overlap->remember(['B']);

        expect($overlap->find(['A', 'B']))->toBe([])
            ->and($overlap->find(['A', 'B', 'C']))->toBe(['C']);
    });

    it('matches a line the live stream cut with the whole line of a one-shot read, both ways', function (): void {
        $whole = '2026-09-25T10:15:02+00:00 app-dev sh[7]: r3long 13 '.str_repeat('y', 10_000);
        $cut = substr($whole, 0, 8_180).' [truncated]';
        $live = new LogTailOverlap;
        $live->remember(['a', $cut, 'b']);
        $ssh = new LogTailOverlap;
        $ssh->remember(['a', $whole, 'b']);

        expect($live->find(['a', $whole, 'b', 'c']))->toBe(['c'])
            ->and($ssh->find(['a', $cut, 'b', 'c']))->toBe(['c'])
            ->and(LogTailOverlap::sameLine($cut, $whole))->toBeTrue()
            ->and(LogTailOverlap::sameLine($cut, $whole.'x'))->toBeTrue()
            ->and(LogTailOverlap::sameLine($cut, 'other'.$whole))->toBeFalse()
            ->and(LogTailOverlap::sameLine('short line [truncated]', 'short line and more'))->toBeFalse();
    });
});
