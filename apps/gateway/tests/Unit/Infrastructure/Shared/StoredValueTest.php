<?php

declare(strict_types=1);

use App\Infrastructure\Shared\StoredValue;

it('accepts integers and valid stored integer strings but rejects malformed values', function (): void {
    expect(StoredValue::integer(42))
        ->toBe(42)
        ->and(StoredValue::integer('42'))
        ->toBe(42)
        ->and(StoredValue::integer('not-an-integer', 7))
        ->toBe(7)
        ->and(StoredValue::integer('999999999999999999999999', 7))
        ->toBe(7);
});

it('uses strings only when stored configuration really contains a string', function (): void {
    expect(StoredValue::string('configured'))
        ->toBe('configured')
        ->and(StoredValue::string(null, 'fallback'))
        ->toBe('fallback');
});
