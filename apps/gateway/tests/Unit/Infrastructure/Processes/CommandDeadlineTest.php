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

it('keeps a local forward-work budget separate from the parent cleanup reserve', function (float $reserve, float $remaining): void {
    $now = 0.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0, $reserve);

    $inside = $deadline->withinForwardWork(10.0, static function () use ($deadline, &$now): float {
        expect($deadline->cap(30.0))->toBe(10.0);
        $now = 2.0;

        return $deadline->cap(30.0);
    });

    expect($inside)->toBe(8.0)->and($deadline->cap(9999.0))->toBe($remaining);
})->with([
    'no reserve' => [0.0, 568.0],
    'five-second reserve' => [5.0, 563.0],
    'API cleanup reserve' => [CommandDeadline::CleanupReserveSeconds, 548.0],
]);

it('restores the parent forward cutoff after a caught local expiry', function (): void {
    $now = 0.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0, CommandDeadline::CleanupReserveSeconds);

    $expire = static function () use ($deadline, &$now): float {
        $now = 10.0;

        return $deadline->cap(30.0);
    };
    expect(fn () => $deadline->withinForwardWork(10.0, $expire))->toThrow(ResourceOperationException::class);

    expect($deadline->cap(9999.0))->toBe(540.0)
        ->and($deadline->withinForwardWork(10.0, static fn (): float => $deadline->cap(30.0)))->toBe(10.0)
        ->and($deadline->cap(9999.0))->toBe(540.0);

    $now = 550.0;
    expect(fn () => $deadline->withinForwardWork(10.0, static fn (): float => $deadline->cap(30.0)))
        ->toThrow(ResourceOperationException::class);
    expect($deadline->cap(30.0))->toBe(20.0);
});

it('keeps request-level cleanup available without allowing another forward operation to consume it', function (): void {
    $now = 0.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0, CommandDeadline::CleanupReserveSeconds);
    $now = 550.0;
    expect(fn () => $deadline->cap(30.0))->toThrow(ResourceOperationException::class);

    expect(fn () => $deadline->withinForwardWork(10.0, static fn (): float => $deadline->cap(30.0)))
        ->toThrow(ResourceOperationException::class);

    expect($deadline->cap(30.0))->toBe(20.0);
    $now = 570.0;
    expect(fn () => $deadline->cap(30.0))->toThrow(ResourceOperationException::class);
});

it('does not extend a shorter parent forward-work deadline or spend its cleanup reserve', function (): void {
    $now = 0.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0, CommandDeadline::CleanupReserveSeconds);
    $now = 547.0;

    $inside = $deadline->withinForwardWork(10.0, static function () use ($deadline, &$now): float {
        expect($deadline->cap(30.0))->toBe(3.0);
        $now = 549.0;

        return $deadline->cap(30.0);
    });

    expect($inside)->toBe(1.0)->and($deadline->cap(30.0))->toBe(1.0);
    $now = 550.0;
    expect(fn () => $deadline->cap(30.0))->toThrow(ResourceOperationException::class);
    expect($deadline->cap(30.0))->toBe(20.0);
});

it('holds time back from work for what must follow it, even after that work ran out of time', function (): void {
    $now = 0.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0, CommandDeadline::CleanupReserveSeconds);

    $inside = $deadline->holding(150.0, static fn (): float => $deadline->cap(9_999.0));

    expect($inside)->toBe(400.0);

    $now = 400.0;
    expect(fn () => $deadline->holding(150.0, static fn (): float => $deadline->cap(9_999.0)))
        ->toThrow(ResourceOperationException::class);

    // The rollback that follows keeps a hold for its own last step, and that step gets the rest.
    expect($deadline->holding(90.0, static fn (): float => $deadline->cap(9_999.0)))->toBe(80.0)
        ->and($deadline->cap(9_999.0))->toBe(170.0);
});
