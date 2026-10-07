<?php

declare(strict_types=1);

it('deliberately fails the required privileged proof gate', function (): void {
    expect(false)->toBeTrue();
})->group('privileged');
