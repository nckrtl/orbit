<?php

declare(strict_types=1);

use App\Domain\Shared\StoredInteger;
use Tests\TestCase;

uses(TestCase::class);

describe(StoredInteger::class, function (): void {
    it('reads an integer or a base-10 string', function (): void {
        expect(StoredInteger::from(12))->toBe(12)
            ->and(StoredInteger::from('12'))->toBe(12)
            ->and(StoredInteger::from('-3'))->toBe(-3);
    });

    it('rejects a value that is not an integer', function (): void {
        expect(fn () => StoredInteger::from('12abc'))
            ->toThrow(UnexpectedValueException::class)
            ->and(fn () => StoredInteger::from(null))
            ->toThrow(UnexpectedValueException::class)
            ->and(fn () => StoredInteger::from(1.5))
            ->toThrow(UnexpectedValueException::class);
    });

    it('treats a missing aggregate as zero and still rejects other values', function (): void {
        expect(StoredInteger::fromOrZero(null))->toBe(0)
            ->and(StoredInteger::fromOrZero('0'))->toBe(0)
            ->and(fn () => StoredInteger::fromOrZero('nope'))
            ->toThrow(UnexpectedValueException::class);
    });

    it('reads a list of integers and rejects anything else', function (): void {
        expect(StoredInteger::listFrom([1, '2']))->toBe([1, 2])
            ->and(fn () => StoredInteger::listFrom('1'))
            ->toThrow(UnexpectedValueException::class)
            ->and(fn () => StoredInteger::listFrom([1, null]))
            ->toThrow(UnexpectedValueException::class);
    });
});
