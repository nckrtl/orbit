<?php

declare(strict_types=1);

use App\Support\Console\Animation;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ConsoleMode;
use App\Support\Console\InterruptIntent;
use App\Support\Console\ProgressDisplay;
use App\Support\Console\SpinnerDisplay;
use Symfony\Component\Console\Output\BufferedOutput;
use UnexpectedValueException;

beforeEach(function (): void {
    InterruptIntent::clear();
});

afterEach(function (): void {
    InterruptIntent::clear();
});

it('records only SIGINT and SIGTERM', function (): void {
    InterruptIntent::record(SIGINT);
    expect(InterruptIntent::pending())->toBe(SIGINT);
    expect(InterruptIntent::exitStatus())->toBe(130);
    InterruptIntent::clear();
    InterruptIntent::record(SIGTERM);
    expect(InterruptIntent::cancellation())->toBeTrue()
        ->and(InterruptIntent::exitStatus())->toBe(143);
    expect(fn () => InterruptIntent::record(SIGKILL))->toThrow(InvalidArgumentException::class);
});

it('restores parent interrupt intent after a nested invocation', function (): void {
    InterruptIntent::record(SIGINT);
    InterruptIntent::run(function (): void {
        expect(InterruptIntent::pending())->toBeNull();
        InterruptIntent::record(SIGTERM);
        expect(InterruptIntent::pending())->toBe(SIGTERM);
    });
    expect(InterruptIntent::pending())->toBe(SIGINT);
});

it('treats ConsoleInterrupted as cancellation even after wrapping', function (): void {
    $interrupted = new ConsoleInterrupted(SIGINT);
    $wrapped = new UnexpectedValueException('Gateway request ID resolver failed.');

    expect(InterruptIntent::cancellation($interrupted))->toBeTrue()
        ->and(InterruptIntent::pending())->toBe(SIGINT)
        ->and(InterruptIntent::cancellation($wrapped))->toBeTrue();
});

it('shows progress interruption when a wrapped resolver failure follows SIGINT', function (): void {
    $output = new BufferedOutput;
    $display = new ProgressDisplay(new ConsoleMode(false, false, false, false, 80), $output, 'Remove App instance');
    $display->admit('remove', 'Remove App instance', 'Removing App instance', 'Removed App instance');
    $wrapped = new UnexpectedValueException('Gateway request ID resolver failed.');

    try {
        $display->during('remove', function () use ($wrapped): never {
            InterruptIntent::record(SIGINT);

            throw $wrapped;
        });
    } catch (UnexpectedValueException $caught) {
        expect($caught)->toBe($wrapped);
    }

    expect($output->fetch())->toContain('Operation interrupted.')->not->toContain('Operation failed.');
    expect(InterruptIntent::pending())->toBe(SIGINT);
});

it('does not settle animation, progress, or spinner as success when a callback swallows the signal and returns', function (): void {
    $mode = new ConsoleMode(false, false, false, false, 80);
    $output = new BufferedOutput;
    $swallowed = function (): int {
        try {
            throw new ConsoleInterrupted(SIGINT);
        } catch (ConsoleInterrupted) {
            return 42;
        }
    };

    expect(fn () => new Animation($mode, $output, ["○ Working\n", "◉ Working\n"])->during($swallowed))
        ->toThrow(ConsoleInterrupted::class);
    expect(InterruptIntent::pending())->toBe(SIGINT);
    InterruptIntent::clear();

    $progress = new ProgressDisplay($mode, $output, 'Remove App instance');
    $progress->admit('remove', 'Remove App instance', 'Removing App instance', 'Removed App instance');
    expect(fn () => $progress->during('remove', $swallowed))->toThrow(ConsoleInterrupted::class);
    expect($output->fetch())->toContain('Operation interrupted.')
        ->not->toContain('Operation failed.', 'Removed App instance');
    expect(InterruptIntent::pending())->toBe(SIGINT);
    InterruptIntent::clear();

    $spinnerOutput = new BufferedOutput;
    $spinner = new SpinnerDisplay($mode, $spinnerOutput);
    expect(fn () => $spinner->during('Waiting', $swallowed))->toThrow(ConsoleInterrupted::class);
    expect($spinnerOutput->fetch())->toContain('Wait interrupted.')->not->toContain('Wait finished.');
    expect(InterruptIntent::pending())->toBe(SIGINT);
});
