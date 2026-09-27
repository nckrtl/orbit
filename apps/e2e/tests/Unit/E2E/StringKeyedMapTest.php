<?php

declare(strict_types=1);

use App\E2E\StringKeyedMap;

it('copies a string-keyed map and keeps an empty map', function (): void {
    $failure = new RuntimeException('The map is invalid.');

    expect(StringKeyedMap::of(['name' => 'vm', 'count' => 1], $failure))
        ->toBe(['name' => 'vm', 'count' => 1])
        ->and(StringKeyedMap::of([], $failure))
        ->toBe([]);
});

it('rejects an integer key instead of treating it as a string field', function (): void {
    expect(fn () => StringKeyedMap::of([0 => 'vm'], new RuntimeException('The map is invalid.')))
        ->toThrow(RuntimeException::class, 'The map is invalid.');
});
