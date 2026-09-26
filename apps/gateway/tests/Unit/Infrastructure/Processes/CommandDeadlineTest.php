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

it('ends forward work early and keeps the reserve for cleanup after the deadline cuts it short', function (): void {
    $now = 0.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0, CommandDeadline::CleanupReserveSeconds);
    $now = 500.0;

    expect($deadline->cap(900.0))->toBe(50.0);

    $now = 550.0;

    expect(fn () => $deadline->cap(60.0))->toThrow(ResourceOperationException::class);

    // Rollback after the failure runs inside the reserve, up to the deadline itself.
    expect($deadline->cap(60.0))->toBe(20.0);

    $now = 570.0;

    expect(fn () => $deadline->cap(60.0))->toThrow(ResourceOperationException::class);
});

it('never lets a nested operation extend the running deadline', function (): void {
    $now = 0.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0);
    $now = 100.0;
    $deadline->start(1_500.0);

    expect($deadline->cap(9_999.0))->toBe(470.0);

    $deadline->start(60.0);

    expect($deadline->cap(9_999.0))->toBe(60.0);
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
            ->and($exception->getMessage())->toBe('The 570-second command deadline was exceeded.');
    });
});

it('restores the request deadline after a nested operation instead of clearing it', function (): void {
    $now = 0.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0, CommandDeadline::CleanupReserveSeconds);

    $inside = $deadline->within(60.0, static function () use ($deadline, &$now): float {
        $now = 10.0;

        return $deadline->cap(9_999.0);
    });

    // The nested deadline keeps the request's cleanup reserve.
    expect($inside)->toBe(30.0)
        ->and($deadline->cap(9_999.0))->toBe(540.0);

    // Without a running deadline, the nested one ends with the operation.
    $deadline->clear();
    $deadline->within(60.0, static fn (): null => null);

    expect($deadline->cap(9_999.0))->toBe(9_999.0);
});
