<?php

declare(strict_types=1);

use App\Support\Console\ConsoleMode;
use App\Support\Console\ProgressDisplay;
use App\Support\Console\ProgressState;
use App\Support\Console\SpinnerDisplay;
use App\Support\Console\TerminalText;
use Symfony\Component\Console\Output\BufferedOutput;

describe('shared progress', function (): void {
    it('runs work exactly once and requires explicit product evidence before success', function (): void {
        $output = new BufferedOutput;
        $display = new ProgressDisplay(new ConsoleMode(false, false, false, false, 60), $output, 'Update resource');
        $display->admit('resolve', 'Resolve resource', 'Resolving resource', 'Resolved resource');
        $display->admit('update', 'Update resource', 'Updating resource', 'Updated resource');
        $calls = 0;
        $result = $display->during('resolve', function () use (&$calls): int {
            $calls++;

            return 42;
        });

        expect($calls)->toBe(1)->and($result)->toBe(42);
        expect(fn () => $display->finish('Done.'))->toThrow(LogicException::class);
        expect(fn () => $display->during('update', fn (): int => 1))->toThrow(LogicException::class);
        $display->complete('resolve', ProgressState::Success);
        $display->during('update', fn (): bool => false);
        $display->complete('update', ProgressState::Failure, 'Update refused.');
        $display->finish('Resource unchanged.');
        $text = $output->fetch();

        expect($text)->toContain('Resolved resource', 'Update refused.', 'Resource unchanged.')
            ->not->toContain("\e", 'Updated resource');
        expect(fn () => $display->complete('update', ProgressState::Success))->toThrow(LogicException::class);
    });

    it('hides unadmitted branches and stops sequential work after failure', function (): void {
        $output = new BufferedOutput;
        $display = new ProgressDisplay(new ConsoleMode(false, false, false, false, 80), $output, 'Operation');
        $display->admit('first', 'First', 'Running first', 'Completed first');
        $display->admit('second', 'Second', 'Running second', 'Completed second');
        $failure = new RuntimeException('private callback detail');

        try {
            $display->during('first', function () use ($failure): never {
                throw $failure;
            });
        } catch (RuntimeException $caught) {
            expect($caught)->toBe($failure);
        }

        expect(fn () => $display->during('second', fn (): int => 1))->toThrow(LogicException::class);
        expect($output->fetch())->toContain('Operation failed.', 'Second')
            ->not->toContain('private callback detail', 'Completed first', 'conditional');
    });

    it('does not emit human output in machine mode and preserves spinner values and exceptions', function (): void {
        $output = new BufferedOutput;
        $mode = new ConsoleMode(true, false, false, false, 80);
        $spinner = new SpinnerDisplay($mode, $output);
        $value = new stdClass;
        $calls = 0;

        expect($spinner->during('Reading', function () use (&$calls, $value): object {
            $calls++;

            return $value;
        }))->toBe($value);
        expect($calls)->toBe(1);
        $failure = new RuntimeException('Expected failure.');

        try {
            $spinner->during('Reading', function () use ($failure): never {
                throw $failure;
            });
        } catch (RuntimeException $caught) {
            expect($caught)->toBe($failure);
        }

        expect($output->fetch())->toBe('');
    });

    it('wraps tree content inside the terminal width without losing text', function (): void {
        $output = new BufferedOutput;
        $display = new ProgressDisplay(new ConsoleMode(false, false, false, false, 20), $output, 'A very long operation title');
        $display->admit('one', 'Read resource', 'Reading a very long resource 名字', 'Read resource');
        $display->during('one', fn (): int => 0);
        $display->complete('one', ProgressState::Warning, 'Long explanation for this outcome');
        $display->finish('Completed with a warning.');

        foreach (explode("\n", $output->fetch()) as $line) {
            expect(TerminalText::width($line))->toBeLessThanOrEqual(20);
        }
    });

    it('preserves the original operation failure when terminal error rendering also fails', function (): void {
        $output = new class extends BufferedOutput
        {
            public bool $fail = false;

            public function writeln(string|iterable $messages, int $options = 0): void
            {
                if ($this->fail) {
                    throw new RuntimeException('Output failed.');
                }

                parent::writeln($messages, $options);
            }
        };
        $display = new ProgressDisplay(new ConsoleMode(false, false, true, true, 80), $output, 'Operation');
        $display->admit('one', 'Read resource', 'Reading resource', 'Read resource');
        $original = new RuntimeException('Original failure.');

        try {
            $display->during('one', function () use ($output, $original): never {
                $output->fail = true;

                throw $original;
            });
        } catch (RuntimeException $caught) {
            expect($caught)->toBe($original);
        }
    });

    it('keeps tiny plain spinner output inside its admitted width', function (): void {
        $output = new BufferedOutput;
        new SpinnerDisplay(new ConsoleMode(false, false, false, false, 1), $output)->during('Wait', fn (): int => 1);

        foreach (explode("\n", $output->fetch()) as $line) {
            expect(TerminalText::width($line))->toBeLessThanOrEqual(1);
        }
    });
});
