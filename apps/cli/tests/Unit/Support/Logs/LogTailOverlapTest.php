<?php

declare(strict_types=1);

use App\Support\Logs\LogTailOverlap;

describe(LogTailOverlap::class, function (): void {
    it('treats every line as new before anything was printed', function (): void {
        $overlap = new LogTailOverlap;

        expect($overlap->hasContext())->toBeFalse()
            ->and($overlap->find(['a', 'b']))->toBe(['a', 'b'])
            ->and($overlap->after([]))->toBe([]);
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

        expect($overlap->find(['c', 'd']))->toBeNull()
            ->and($overlap->after(['c', 'd']))->toBe(['c', 'd']);
    });

    it('prints every fetched line when no overlap is found', function (): void {
        $overlap = new LogTailOverlap;
        $overlap->remember(['a', 'b']);

        expect($overlap->find(['x', 'y']))->toBeNull()
            ->and($overlap->after(['x', 'y']))->toBe(['x', 'y']);
    });
});
