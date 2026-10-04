<?php

declare(strict_types=1);

use App\E2E\HostCapacity;
use Illuminate\Support\Facades\Process;

it('accepts a numeric Incus VM capacity', function (int|float|string $capacity): void {
    config(['e2e.incus.max_vms' => $capacity]);
    app()->forgetInstance(HostCapacity::class);
    Process::fake(['*' => Process::result(json_encode(array_map(
        static fn (int $index): array => [
            'name' => 'orbit-e2e-capacity-'.$index,
            'type' => 'virtual-machine',
            'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
        ],
        range(1, 24),
    ), JSON_THROW_ON_ERROR))]);

    expect(fn () => app(HostCapacity::class)->reserveSlot())
        ->toThrow(RuntimeException::class, 'the limit is 24');
})->with([
    'integer' => 24,
    'digits' => '24',
    'float' => 24.0,
    'decimal' => '24.0',
    'signed' => '+24',
    'whitespace' => ' 24 ',
]);

it('rejects a malformed Incus VM capacity', function (mixed $capacity): void {
    config(['e2e.incus.max_vms' => $capacity]);
    app()->forgetInstance(HostCapacity::class);

    expect(fn () => app(HostCapacity::class))
        ->toThrow(RuntimeException::class, 'Incus host capacity is invalid.');
})->with([
    'partial number' => '24abc',
    'list' => [[24]],
]);
