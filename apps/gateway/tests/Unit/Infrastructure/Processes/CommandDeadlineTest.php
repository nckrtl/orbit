<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;

it('shares one decreasing budget across sequential process work', function (): void {
    $now = 100.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });

    expect($deadline->cap(60.0))->toBe(60.0);

    $deadline->start(900.0);
    $now = 350.0;

    expect($deadline->cap(900.0))
        ->toBe(650.0)
        ->and($deadline->cap(60.0))
        ->toBe(60.0);

    $now = 999.5;

    expect($deadline->cap(900.0))->toBe(0.5);

    $deadline->clear();

    expect($deadline->cap(900.0))->toBe(900.0);
});

it('fails an expired command with a stable error that names the deadline', function (): void {
    $now = 100.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0);
    $now = 670.0;

    expect(fn () => $deadline->cap(60.0))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('command.deadline_exceeded')
            ->and($exception->status)->toBe(504)
            ->and($exception->getMessage())->toBe('The 570-second API command deadline was exceeded.');
    });
});
