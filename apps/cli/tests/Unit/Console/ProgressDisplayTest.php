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

    it('colors the failure footer red and leaves a healthy footer uncolored (F2c)', function (): void {
        $output = new BufferedOutput;
        $display = new ProgressDisplay(new ConsoleMode(false, false, true, false, 80), $output, 'Operation');
        $display->admit('first', 'First', 'Running first', 'Completed first');
        $display->during('first', fn (): bool => true);
        $display->complete('first', ProgressState::Failure, 'boom');
        $display->finish('Operation failed.');
        $text = $output->fetch();

        expect($text)->toContain("\e[31mOperation failed.\e[0m");

        $healthy = new BufferedOutput;
        $ok = new ProgressDisplay(new ConsoleMode(false, false, true, false, 80), $healthy, 'Operation');
        $ok->admit('first', 'First', 'Running first', 'Completed first');
        $ok->during('first', fn (): bool => true);
        $ok->complete('first', ProgressState::Success);
        $ok->finish('Operation succeeded.');

        expect($healthy->fetch())->not->toContain("\e[31m");
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

    it('inserts a revealed step at its real position on a decorated terminal', function (): void {
        $output = new BufferedOutput;
        $display = new ProgressDisplay(new ConsoleMode(false, false, true, true, 80), $output, 'Deploy AppInstance [17]');
        $display->admit('source_preparation', 'Resolve release', 'Resolving release', 'Resolved release');
        $display->admit('activation', 'Activate release', 'Activating release', 'Activated release');
        $display->during('source_preparation', fn (): int => 0);
        $display->complete('source_preparation', ProgressState::Success);
        $display->admitBefore('activation', 'before_activation:migrate', 'Run migrate', 'Running migrate', 'Ran migrate');
        $display->during('before_activation:migrate', fn (): int => 0);
        $display->complete('before_activation:migrate', ProgressState::Success);
        $display->during('activation', fn (): int => 0);
        $display->complete('activation', ProgressState::Success);
        $display->finish('Deployment succeeded.');
        $text = $output->fetch();
        $finalFrame = substr($text, strrpos($text, '┌'));

        expect($text)->toContain("\e[")
            ->and($finalFrame)->toContain('Resolved release', 'Ran migrate', 'Activated release')
            ->and(strpos($finalFrame, 'Resolved release'))->toBeLessThan(strpos($finalFrame, 'Ran migrate'))
            ->and(strpos($finalFrame, 'Ran migrate'))->toBeLessThan(strpos($finalFrame, 'Activated release'));
    });

    it('inserts a revealed step at its real position on plain output', function (): void {
        $output = new BufferedOutput;
        $display = new ProgressDisplay(new ConsoleMode(false, false, false, false, 80), $output, 'Deploy AppInstance [17]');
        $display->admit('source_preparation', 'Resolve release', 'Resolving release', 'Resolved release');
        $display->admit('activation', 'Activate release', 'Activating release', 'Activated release');
        $display->during('source_preparation', fn (): int => 0);
        $display->complete('source_preparation', ProgressState::Success);
        $display->admitBefore('activation', 'before_activation:migrate', 'Run migrate', 'Running migrate', 'Ran migrate');
        $display->during('before_activation:migrate', fn (): int => 0);
        $display->complete('before_activation:migrate', ProgressState::Success);
        $display->during('activation', fn (): int => 0);
        $display->complete('activation', ProgressState::Success);
        $display->finish('Deployment succeeded.');
        $text = $output->fetch();
        $finalFrame = substr($text, strrpos($text, '┌'));

        expect($text)->not->toContain("\e[")
            ->and(strpos($finalFrame, 'Resolved release'))->toBeLessThan(strpos($finalFrame, 'Ran migrate'))
            ->and(strpos($finalFrame, 'Ran migrate'))->toBeLessThan(strpos($finalFrame, 'Activated release'));
    });

    it('fails loudly when the anchor step for admitBefore does not exist', function (): void {
        $output = new BufferedOutput;
        $display = new ProgressDisplay(new ConsoleMode(false, false, false, false, 80), $output, 'Deploy AppInstance [17]');
        $display->admit('source_preparation', 'Resolve release', 'Resolving release', 'Resolved release');

        expect(fn () => $display->admitBefore('activation', 'before_activation:migrate', 'Run migrate', 'Running migrate', 'Ran migrate'))
            ->toThrow(LogicException::class);
    });
});
