<?php

declare(strict_types=1);

use App\Domain\Shared\Configured;
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

describe(Configured::class, function (): void {
    it('reads a configured string or integer', function (): void {
        config()->set('orbit.testing.configured_string', 'gateway');
        config()->set('orbit.testing.configured_int', 15);

        expect(Configured::string('orbit.testing.configured_string'))->toBe('gateway')
            ->and(Configured::int('orbit.testing.configured_int'))->toBe(15);
    });

    it('uses the default only when the entry is missing', function (): void {
        expect(Configured::string('orbit.testing.missing_string', 't3'))->toBe('t3')
            ->and(Configured::int('orbit.testing.missing_int', 120))->toBe(120);
    });

    it('rejects a configured value of the wrong type', function (): void {
        config()->set('orbit.testing.configured_string', 12);
        config()->set('orbit.testing.configured_int', '12');

        expect(fn () => Configured::string('orbit.testing.configured_string'))
            ->toThrow(RuntimeException::class, 'Configuration [orbit.testing.configured_string] must be a string.')
            ->and(fn () => Configured::int('orbit.testing.configured_int'))
            ->toThrow(RuntimeException::class, 'Configuration [orbit.testing.configured_int] must be an integer.');
    });
});
